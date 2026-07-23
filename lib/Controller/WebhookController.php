<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Controller;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use SignDocsBrasil\Api\WebhookVerifier;

class WebhookController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly SigningSessionService $service,
		private readonly CredentialsService $credentials,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @CORS
	 */
	public function receive(): DataResponse {
		$secret = $this->credentials->getWebhookSecret();
		if ($secret === null) {
			$this->logger->warning('SignDocs webhook hit but no secret configured; rejecting.');
			return new DataResponse(['error' => 'webhook_not_configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$body = file_get_contents('php://input') ?: '';
		$signature = $this->request->getHeader('X-SignDocs-Signature');
		$timestamp = $this->request->getHeader('X-SignDocs-Timestamp');

		$valid = WebhookVerifier::verify(
			body: $body,
			signatureHeader: $signature,
			timestampHeader: $timestamp,
			secret: $secret,
		);
		if (!$valid) {
			$this->logger->warning('Rejected SignDocs webhook (HMAC mismatch or expired timestamp)');
			return new DataResponse(['error' => 'invalid_signature'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return new DataResponse(['error' => 'malformed_event'], Http::STATUS_BAD_REQUEST);
		}

		if (!is_array($event)) {
			return new DataResponse(['error' => 'malformed_event'], Http::STATUS_BAD_REQUEST);
		}

		$parsed = self::extractEvent($event);
		if ($parsed === null) {
			// Non-terminal / informational event (…CREATED, STEP.*,
			// …DEADLINE_APPROACHING, QUOTA.WARNING) — acknowledge, do nothing.
			return new DataResponse(['ok' => true, 'ignored' => true]);
		}
		if ($parsed['transactionId'] === null && $parsed['sessionId'] === null) {
			return new DataResponse(['error' => 'malformed_event'], Http::STATUS_BAD_REQUEST);
		}

		$this->service->applyStatusUpdateFromWebhook(
			$parsed['transactionId'],
			$parsed['sessionId'],
			$parsed['status'],
			$parsed['signedFileId'],
		);

		return new DataResponse(['ok' => true]);
	}

	/**
	 * Resolve correlation ids + a terminal status from a decoded webhook event.
	 *
	 * Pure and side-effect-free so it can be unit-tested against real payload
	 * shapes without php://input. Returns null for non-terminal/informational
	 * events (…CREATED, STEP.*, …DEADLINE_APPROACHING, QUOTA.WARNING) that carry
	 * no actionable status.
	 *
	 * Handles both single-signer (TRANSACTION.*, keyed by transactionId) and
	 * multi-signer (ENVELOPE.*, keyed by data.envelopeId) shapes. ENVELOPE.ALL_SIGNED
	 * carries no `data.status`, so the status is derived from the event type; its
	 * top-level `transactionId` is the last signer's tx (not the envelope) and is
	 * deliberately ignored in favour of data.envelopeId.
	 *
	 * @param array<string, mixed> $event
	 * @return array{transactionId: ?string, sessionId: ?string, status: string, signedFileId: ?string}|null
	 */
	public static function extractEvent(array $event): ?array {
		$eventType = is_string($event['eventType'] ?? null) ? $event['eventType'] : '';
		$data = is_array($event['data'] ?? null) ? $event['data'] : [];

		$transactionId = self::firstString([$event['transactionId'] ?? null, $data['transactionId'] ?? null]);
		$sessionId = self::firstString([
			$event['sessionId'] ?? null,
			$event['envelopeId'] ?? null,
			$data['sessionId'] ?? null,
			$data['envelopeId'] ?? null,
		]);
		$status = self::firstString([$event['status'] ?? null, $data['status'] ?? null])
			?? self::statusFromEventType($eventType);
		if ($status === null) {
			return null;
		}

		// For envelope events, correlate by the envelope id only — the top-level
		// transactionId is the last signer's tx, which this mirror never stored.
		if (str_starts_with($eventType, 'ENVELOPE.')) {
			$transactionId = null;
		}

		return [
			'transactionId' => $transactionId,
			'sessionId' => $sessionId,
			'status' => $status,
			'signedFileId' => self::firstString([$data['signedDocumentId'] ?? null]),
		];
	}

	private static function statusFromEventType(string $eventType): ?string {
		return match ($eventType) {
			'TRANSACTION.COMPLETED', 'ENVELOPE.ALL_SIGNED' => 'completed',
			'TRANSACTION.CANCELLED', 'ENVELOPE.CANCELLED' => 'cancelled',
			'TRANSACTION.EXPIRED', 'ENVELOPE.EXPIRED' => 'expired',
			'TRANSACTION.FAILED' => 'failed',
			default => null,
		};
	}

	/**
	 * @param array<int, mixed> $candidates
	 */
	private static function firstString(array $candidates): ?string {
		foreach ($candidates as $c) {
			if (is_string($c) && $c !== '') {
				return $c;
			}
		}
		return null;
	}
}
