<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\BackgroundJob;

use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

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
				// Cheaper than ->get(); status is enough to drive tag/notification updates.
				$status = $client->signingSessions->getStatus($entity->getSessionId());
				if ($status->status !== $entity->getStatus()) {
					$this->sessionService->applyStatusUpdate(
						$entity->getSessionId(),
						$status->status,
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
}
