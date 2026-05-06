<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Controller;

use OCA\SignDocsBrasil\Controller\SigningController;
use OCA\SignDocsBrasil\Db\SigningSession;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\NotConnectedException;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SigningController exception → HTTP-status mapping.
 *
 * The SigningSessionService can raise four meaningful exception classes:
 *   - NotConnectedException        → 412 Precondition Failed
 *   - InvalidArgumentException     → 422 Unprocessable Entity (constraint
 *                                    violations like ICP + parallel + 2+
 *                                    signers, caught BEFORE the SDK call)
 *   - any other Throwable          → 500 Internal Server Error
 *
 * These tests pin those mappings so a future refactor can't silently
 * downgrade a 422 to a 500 (which would mask actionable client errors as
 * server bugs in monitoring dashboards).
 */
class SigningControllerTest extends TestCase {
	/** @var IRequest&MockObject */
	private $request;
	/** @var SigningSessionService&MockObject */
	private $service;
	/** @var SigningSessionMapper&MockObject */
	private $mapper;
	/** @var IUserSession&MockObject */
	private $userSession;
	/** @var LoggerInterface&MockObject */
	private $logger;

	private SigningController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(SigningSessionService::class);
		$this->mapper = $this->createMock(SigningSessionMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new SigningController(
			$this->request,
			$this->service,
			$this->mapper,
			$this->userSession,
			$this->logger,
		);
	}

	public function testCreateMaps422OnConstraintViolation(): void {
		// Service rejects ICP+PARALLEL+multi-signer before hitting the SDK.
		$this->service->method('createForFile')
			->willThrowException(new \InvalidArgumentException(
				'Digital certificate signing requires sequential order with multiple signers.'
			));

		$response = $this->controller->create(
			fileId: 42,
			signers: [['name' => 'A', 'email' => 'a@b.com', 'cpf' => '12345678901'], ['name' => 'B', 'email' => 'b@c.com', 'cpf' => '98765432101']],
			options: ['mode' => 'digital_certificate', 'order' => 'parallel'],
		);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('invalid_input', $response->getData()['error']);
		self::assertStringContainsString('sequential', $response->getData()['message']);
	}

	public function testCreateMaps412WhenUserNotConnected(): void {
		$this->service->method('createForFile')
			->willThrowException(new NotConnectedException('User has not linked a SignDocs Brasil account yet.'));

		$response = $this->controller->create(42, [], []);

		self::assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		self::assertSame('not_connected', $response->getData()['error']);
	}

	public function testCreateMaps500ForUnexpectedFailures(): void {
		// SDK transient or filesystem error — not the user's problem.
		// We log it AND return 500 so monitoring catches it.
		$this->service->method('createForFile')
			->willThrowException(new \RuntimeException('SDK timeout'));
		$this->logger->expects(self::once())->method('error');

		$response = $this->controller->create(42, [], []);

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('create_failed', $response->getData()['error']);
	}

	public function testCreateReturnsSessionDataOnSuccess(): void {
		$entity = new SigningSession();
		$entity->setSessionId('sess_abc123');
		$entity->setStatus('pending');
		$entity->setMetadata(json_encode(['kind' => 'session', 'transactionId' => 'tx_x']));

		$this->service->method('createForFile')->willReturn($entity);

		$response = $this->controller->create(42, [['name' => 'A', 'email' => 'a@b.com', 'cpf' => '12345678901']], []);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('sess_abc123', $response->getData()['sessionId']);
		self::assertSame('pending', $response->getData()['status']);
		self::assertIsArray($response->getData()['metadata']);
	}
}
