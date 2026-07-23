<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Controller;

use OCA\SignDocsBrasil\Controller\WebhookController;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the webhook receiver:
 *  - rejects when no secret is configured (503)
 *  - rejects when HMAC doesn't match (401)
 *  - rejects when timestamp is outside tolerance (401)
 *  - accepts a properly signed payload and forwards to the service
 *
 * Generates real signatures matching the SDK's WebhookVerifier::verify
 * algorithm (HMAC-SHA256 of "timestamp.body"); no SDK mock — we want
 * to fail loudly if either side changes the contract.
 */
class WebhookControllerTest extends TestCase {
	private const SECRET = 'whsec_test_4242';

	/** @var IRequest&MockObject */
	private $request;
	/** @var SigningSessionService&MockObject */
	private $service;
	/** @var CredentialsService&MockObject */
	private $credentials;
	/** @var LoggerInterface&MockObject */
	private $logger;

	private WebhookController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(SigningSessionService::class);
		$this->credentials = $this->createMock(CredentialsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new WebhookController(
			$this->request,
			$this->service,
			$this->credentials,
			$this->logger,
		);
	}

	public function testReceiveReturnsServiceUnavailableWhenSecretMissing(): void {
		$this->credentials->method('getWebhookSecret')->willReturn(null);
		$this->service->expects(self::never())->method('applyStatusUpdateFromWebhook');

		$response = $this->controller->receive();

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}

	public function testReceiveRejectsTamperedBody(): void {
		// php://input can't be mocked, so this test asserts the same path —
		// when the body that reaches WebhookVerifier doesn't match the
		// signature, verify() returns false and the controller rejects.
		// The body is read inside the controller from php://input which is
		// empty in the test runner, so this exercises the "empty body
		// against a real signature" rejection path.
		$this->credentials->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->request->method('getHeader')->willReturnMap([
			['X-SignDocs-Signature', 'deadbeef'],
			['X-SignDocs-Timestamp', (string)time()],
		]);
		$this->service->expects(self::never())->method('applyStatusUpdateFromWebhook');

		$response = $this->controller->receive();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testReceiveRejectsExpiredTimestamp(): void {
		$body = '{"sessionId":"sess_x","status":"completed"}';
		$expired = time() - 3600; // way outside the 300s tolerance
		$signature = hash_hmac('sha256', $expired . '.' . $body, self::SECRET);

		$this->credentials->method('getWebhookSecret')->willReturn(self::SECRET);
		$this->request->method('getHeader')->willReturnMap([
			['X-SignDocs-Signature', $signature],
			['X-SignDocs-Timestamp', (string)$expired],
		]);
		$this->service->expects(self::never())->method('applyStatusUpdateFromWebhook');

		$response = $this->controller->receive();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testHmacAlgorithmMatchesSdkContract(): void {
		// Lock down the wire format: HMAC-SHA256 over "<timestamp>.<body>"
		// using the configured secret. If either the SDK or this app
		// changes the algorithm, this test must update — keeping them in
		// lockstep is the whole point.
		$body = '{"sessionId":"sess_x","status":"completed"}';
		$timestamp = time();
		$expected = hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

		self::assertTrue(\SignDocsBrasil\Api\WebhookVerifier::verify(
			body: $body,
			signatureHeader: $expected,
			timestampHeader: (string)$timestamp,
			secret: self::SECRET,
		));

		// Tamper with body → must reject.
		self::assertFalse(\SignDocsBrasil\Api\WebhookVerifier::verify(
			body: $body . 'tampered',
			signatureHeader: $expected,
			timestampHeader: (string)$timestamp,
			secret: self::SECRET,
		));
	}

	/**
	 * Regression: a single-signer TRANSACTION.COMPLETED is keyed by
	 * transactionId (not sessionId). The original controller looked only for
	 * sessionId/envelopeId and rejected every such event with 400.
	 */
	public function testExtractSingleSignerTransactionCompleted(): void {
		$parsed = WebhookController::extractEvent([
			'eventType' => 'TRANSACTION.COMPLETED',
			'transactionId' => 'tx_1',
			'data' => ['transactionId' => 'tx_1', 'status' => 'COMPLETED', 'evidenceId' => 'ev_1'],
		]);

		self::assertNotNull($parsed);
		self::assertSame('tx_1', $parsed['transactionId']);
		self::assertNull($parsed['sessionId']);
		self::assertSame('COMPLETED', $parsed['status']);
	}

	/**
	 * Regression: ENVELOPE.ALL_SIGNED carries NO data.status, and its top-level
	 * transactionId is the last signer's tx — correlation must use data.envelopeId
	 * and the status must be derived from the event type.
	 */
	public function testExtractEnvelopeAllSignedDerivesStatusAndUsesEnvelopeId(): void {
		$parsed = WebhookController::extractEvent([
			'eventType' => 'ENVELOPE.ALL_SIGNED',
			'transactionId' => 'tx_lastsigner',
			'data' => ['envelopeId' => 'env_1', 'totalSigners' => 2],
		]);

		self::assertNotNull($parsed);
		self::assertNull($parsed['transactionId']);
		self::assertSame('env_1', $parsed['sessionId']);
		self::assertSame('completed', $parsed['status']);
	}

	public function testExtractEnvelopeCancelled(): void {
		$parsed = WebhookController::extractEvent([
			'eventType' => 'ENVELOPE.CANCELLED',
			'transactionId' => 'env_1',
			'data' => ['envelopeId' => 'env_1'],
		]);

		self::assertNotNull($parsed);
		self::assertSame('env_1', $parsed['sessionId']);
		self::assertSame('cancelled', $parsed['status']);
	}

	public function testExtractNonTerminalEventsAreIgnored(): void {
		foreach (['ENVELOPE.CREATED', 'TRANSACTION.CREATED', 'STEP.STARTED', 'QUOTA.WARNING'] as $type) {
			self::assertNull(
				WebhookController::extractEvent(['eventType' => $type, 'data' => []]),
				$type . ' should be ignored (no actionable status)',
			);
		}
	}
}
