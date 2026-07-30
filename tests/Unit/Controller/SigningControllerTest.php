<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Controller;

use OCA\SignDocsBrasil\Controller\SigningController;
use OCA\SignDocsBrasil\Db\SigningSession;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\NotConnectedException;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
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
	/** @var IRootFolder&MockObject */
	private $rootFolder;
	/** @var Folder&MockObject */
	private $userFolder;

	private SigningController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(SigningSessionService::class);
		$this->mapper = $this->createMock(SigningSessionMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);

		$this->controller = new SigningController(
			$this->request,
			$this->service,
			$this->mapper,
			$this->userSession,
			$this->logger,
			$this->rootFolder,
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

	public function testListForUserEnrichesRowsForTheUi(): void {
		// The request list renders straight from this payload, so it has to carry
		// the file name, the signer count and whether anything is left to cancel
		// — otherwise the UI needs a round-trip per row.
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);

		$entity = new \OCA\SignDocsBrasil\Db\SigningSession();
		$entity->setSessionId('env_1');
		$entity->setFileId(7);
		$entity->setUserId('admin');
		$entity->setStatus('pending');
		$entity->setMetadata(json_encode([
			'kind' => 'envelope',
			'shareLinks' => [['sessionId' => 'ss_a'], ['sessionId' => 'ss_b']],
		], JSON_THROW_ON_ERROR));
		$this->mapper->method('findByUser')->willReturn([$entity]);

		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('getName')->willReturn('Contrato.pdf');
		$this->userFolder->method('getById')->willReturn([$file]);

		$row = $this->controller->listForUser()->getData()[0];

		self::assertSame('env_1', $row['sessionId']);
		self::assertSame('Contrato.pdf', $row['fileName']);
		self::assertSame('envelope', $row['kind']);
		self::assertSame(2, $row['signerCount']);
		self::assertTrue($row['cancellable']);
	}

	public function testListForUserMarksTerminalRowsUncancellable(): void {
		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);

		$rows = [];
		foreach (['completed', 'cancelled', 'pending'] as $status) {
			$e = new \OCA\SignDocsBrasil\Db\SigningSession();
			$e->setSessionId('ss_' . $status);
			$e->setFileId(7);
			$e->setStatus($status);
			$e->setMetadata('{"kind":"session"}');
			$rows[] = $e;
		}
		$this->mapper->method('findByUser')->willReturn($rows);
		$this->userFolder->method('getById')->willReturn([]);

		$data = $this->controller->listForUser()->getData();

		self::assertFalse($data[0]['cancellable'], 'completed');
		self::assertFalse($data[1]['cancellable'], 'cancelled');
		self::assertTrue($data[2]['cancellable'], 'pending');
		// A deleted document still yields a row the UI can render.
		self::assertNull($data[0]['fileName']);
	}
}
