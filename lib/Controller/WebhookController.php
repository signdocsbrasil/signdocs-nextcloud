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

		$sessionId = $event['sessionId'] ?? $event['envelopeId'] ?? $event['data']['sessionId'] ?? $event['data']['envelopeId'] ?? null;
		$status = $event['status'] ?? $event['data']['status'] ?? null;
		$signedFileId = $event['data']['signedDocumentId'] ?? null;

		if (!is_string($sessionId) || !is_string($status)) {
			return new DataResponse(['error' => 'malformed_event'], Http::STATUS_BAD_REQUEST);
		}

		$this->service->applyStatusUpdate($sessionId, $status, $signedFileId);

		return new DataResponse(['ok' => true]);
	}
}
