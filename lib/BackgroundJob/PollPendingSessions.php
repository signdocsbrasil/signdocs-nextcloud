<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\BackgroundJob;

use OCA\SignDocsBrasil\Db\SigningSession;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use SignDocsBrasil\Api\SignDocsBrasilClient;

/**
 * Reconciles pending signing sessions on a timer.
 *
 * Required for NC instances behind firewalls / NAT where SignDocs cannot
 * deliver webhooks. Stale-pending sessions older than 10 minutes get a
 * status check; if the API says they're done, we apply the same side-effects
 * the webhook would have.
 */
class PollPendingSessions extends TimedJob {
	private const STALE_THRESHOLD_SECONDS = 600;
	private const BATCH_SIZE = 50;

	public function __construct(
		ITimeFactory $time,
		private readonly SigningSessionMapper $mapper,
		private readonly SignDocsClientFactory $clientFactory,
		private readonly SigningSessionService $sessionService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
	}

	protected function run($argument): void {
		$cutoff = $this->time->getTime() - self::STALE_THRESHOLD_SECONDS;
		$pending = $this->mapper->findPendingOlderThan($cutoff, self::BATCH_SIZE);

		if (empty($pending)) {
			return;
		}

		foreach ($pending as $entity) {
			try {
				$client = $this->clientFactory->forUser($entity->getUserId());
				$status = $this->fetchStatus($client, $entity);
				if ($status !== $entity->getStatus()) {
					$this->sessionService->applyStatusUpdate(
						$entity->getSessionId(),
						$status,
					);
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Failed to poll SignDocs session', [
					'sessionId' => $entity->getSessionId(),
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * Current upstream status for a mirror row. Single-signer flows expose it via
	 * signingSessions->getStatus; envelopes (session_id == envelopeId) via
	 * envelopes->get, because getStatus rejects an envelope id. This is why the
	 * poller is the reconciliation fallback for firewalled instances on both the
	 * single-signer and the multi-signer path.
	 */
	private function fetchStatus(SignDocsBrasilClient $client, SigningSession $entity): string {
		$meta = json_decode((string)$entity->getMetadata(), true);
		$kind = is_array($meta) ? ($meta['kind'] ?? 'session') : 'session';
		if ($kind === 'envelope') {
			return $client->envelopes->get($entity->getSessionId())->status;
		}
		return $client->signingSessions->getStatus($entity->getSessionId())->status;
	}
}
