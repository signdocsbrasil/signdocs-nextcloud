<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\BackgroundJob;

use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use SignDocsBrasil\Api\Errors\NotFoundException as ApiNotFoundException;

/**
 * Fetches the signed / combined PDF for completed sessions and saves it into
 * the user's signed folder (default /Assinados).
 *
 * The webhook and the polling job only reconcile *status*; the signed artifact
 * is retrieved separately here because completion events carry no document —
 * single-signer flows expose it via the per-transaction download and envelopes
 * via the combined stamp. Runs on a timer and is idempotent (rows already
 * carrying a signed_file_id are skipped by the mapper query).
 */
class FetchSignedDocuments extends TimedJob {
	private const BATCH_SIZE = 20;

	public function __construct(
		ITimeFactory $time,
		private readonly SigningSessionMapper $mapper,
		private readonly SigningSessionService $sessionService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
	}

	protected function run($argument): void {
		$pending = $this->mapper->findCompletedWithoutSignedFile(self::BATCH_SIZE);
		if (empty($pending)) {
			return;
		}

		foreach ($pending as $entity) {
			try {
				$this->sessionService->saveSignedDocument($entity);
			} catch (ApiNotFoundException $e) {
				// Same reasoning as PollPendingSessions: a 404 means the
				// transaction is gone upstream, so no future run can retrieve
				// the artifact. Settle the row instead of re-asking every run.
				$this->sessionService->markNoSignedArtifact(
					$entity,
					'transaction no longer exists upstream',
				);
				$this->logger->info('Signed document no longer retrievable; stopped polling for it', [
					'sessionId' => $entity->getSessionId(),
				]);
			} catch (\Throwable $e) {
				// Transient failures (artifact not ready, network) are retried
				// on the next run; log and move on so one bad row can't stall
				// the batch.
				$this->logger->warning('Failed to save signed document', [
					'sessionId' => $entity->getSessionId(),
					'exception' => $e,
				]);
			}
		}
	}
}
