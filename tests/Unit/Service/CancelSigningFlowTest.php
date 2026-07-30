<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\Db\SigningSession;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SignDocsBrasil\Api\HttpClient;
use SignDocsBrasil\Api\Resources\EnvelopesResource;
use SignDocsBrasil\Api\Resources\SigningSessionsResource;

/**
 * Cancelling a signing flow.
 *
 * An envelope is cancelled through the envelope's own endpoint — the same one
 * the Telegram bot calls. Cancelling the member sessions one by one is not
 * equivalent: it leaves the envelope's own status ACTIVE, costs a call per
 * signer, and records N separate events instead of one auditable cancellation.
 */
class CancelSigningFlowTest extends TestCase {
	private SigningSessionMapper $mapper;
	private SigningSessionService $service;
	private ISystemTagObjectMapper $tagObjectMapper;

	/** @var array<int, array{method: string, path: string, body: mixed}> */
	private array $calls = [];
	/** @var array<string, mixed> canned response for every request */
	private array $response = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(SigningSessionMapper::class);
		$this->tagObjectMapper = $this->createMock(ISystemTagObjectMapper::class);

		// The SDK resources are final and cannot be mocked; build real ones over
		// a mocked HttpClient, which also exercises the SDK's own request shaping.
		$http = $this->createMock(HttpClient::class);
		$http->method('request')->willReturnCallback(
			function (string $method, string $path, $body = null) {
				$this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
				return $this->response;
			},
		);

		$clientFactory = $this->createMock(SignDocsClientFactory::class);
		$clientFactory->method('envelopesFor')->willReturn(new EnvelopesResource($http));
		$clientFactory->method('signingSessionsFor')->willReturn(new SigningSessionsResource($http));

		$tagManager = $this->createMock(ISystemTagManager::class);
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn('33');
		$tagManager->method('getAllTags')->willReturn([$tag]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1785350000);

		$this->service = new SigningSessionService(
			$clientFactory,
			$this->mapper,
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserSession::class),
			$tagManager,
			$this->tagObjectMapper,
			$time,
			$this->createMock(LoggerInterface::class),
			$this->createMock(CredentialsService::class),
			$this->createMock(IClientService::class),
		);
	}

	/** @param array<string, mixed> $metadata */
	private function given(string $sessionId, array $metadata): SigningSession {
		$entity = new SigningSession();
		$entity->setSessionId($sessionId);
		$entity->setFileId(136);
		$entity->setUserId('admin');
		$entity->setStatus('pending');
		$entity->setMetadata(json_encode($metadata, JSON_THROW_ON_ERROR));
		$this->mapper->method('findBySessionId')->willReturn($entity);
		return $entity;
	}

	public function testEnvelopeUsesTheEnvelopeCancelEndpoint(): void {
		$entity = $this->given('env_1', [
			'kind' => 'envelope',
			'shareLinks' => [['sessionId' => 'ss_a'], ['sessionId' => 'ss_b']],
		]);
		$this->response = [
			'envelopeId' => 'env_1',
			'status' => 'CANCELLED',
			'cancelledCount' => 2,
			'preservedSignedCount' => 0,
			'cancelledSessions' => [],
		];

		$result = $this->service->cancelSigningFlow('env_1');

		self::assertCount(1, $this->calls, 'one call, not one per signer');
		self::assertSame('POST', $this->calls[0]['method']);
		self::assertSame('/v1/envelopes/env_1/cancel', $this->calls[0]['path']);
		self::assertSame(2, $result['cancelled']);
		self::assertSame('cancelled', $entity->getStatus());
	}

	public function testEnvelopeCancelCarriesAReasonForTheAuditTrail(): void {
		$this->given('env_1', ['kind' => 'envelope']);
		$this->response = ['envelopeId' => 'env_1', 'status' => 'CANCELLED'];

		$this->service->cancelSigningFlow('env_1');
		self::assertSame(['reason' => 'cancelled_via_nextcloud'], $this->calls[0]['body']);

		$this->calls = [];
		$this->service->cancelSigningFlow('env_1', 'owner_changed_their_mind');
		self::assertSame(['reason' => 'owner_changed_their_mind'], $this->calls[0]['body']);
	}

	public function testAlreadyCollectedSignaturesArePreserved(): void {
		// Cancelling stops the pending signers; it never invalidates evidence
		// that was already gathered.
		$this->given('env_1', ['kind' => 'envelope']);
		$this->response = [
			'envelopeId' => 'env_1',
			'status' => 'CANCELLED',
			'cancelledCount' => 1,
			'preservedSignedCount' => 2,
		];

		$result = $this->service->cancelSigningFlow('env_1');

		self::assertSame(1, $result['cancelled']);
		self::assertSame(2, $result['preservedSigned']);
	}

	public function testReCancellingIsANoOpNotAnError(): void {
		$entity = $this->given('env_1', ['kind' => 'envelope']);
		$this->response = [
			'envelopeId' => 'env_1',
			'status' => 'CANCELLED',
			'cancelledCount' => 0,
			'alreadyCancelled' => true,
		];

		$result = $this->service->cancelSigningFlow('env_1');

		self::assertTrue($result['alreadyCancelled']);
		self::assertSame(0, $result['cancelled']);
		self::assertSame('cancelled', $entity->getStatus(), 'mirror still reconciles');
	}

	public function testSingleSignerUsesTheSessionEndpoint(): void {
		$entity = $this->given('ss_1', ['kind' => 'session', 'transactionId' => 'tx_1']);
		$this->response = ['sessionId' => 'ss_1', 'transactionId' => 'tx_1', 'status' => 'CANCELLED', 'cancelledAt' => 'now'];

		$result = $this->service->cancelSigningFlow('ss_1');

		self::assertSame('/v1/signing-sessions/ss_1/cancel', $this->calls[0]['path']);
		self::assertSame(1, $result['cancelled']);
		self::assertSame('cancelled', $entity->getStatus());
	}

	public function testARowWithoutAKindIsTreatedAsASingleSession(): void {
		$this->given('ss_1', []);
		$this->response = ['sessionId' => 'ss_1', 'status' => 'CANCELLED'];

		$this->service->cancelSigningFlow('ss_1');

		self::assertStringContainsString('/v1/signing-sessions/', $this->calls[0]['path']);
	}

	public function testAFailedCancelLeavesTheRowAlone(): void {
		$entity = $this->given('env_1', ['kind' => 'envelope']);

		$http = $this->createMock(HttpClient::class);
		$http->method('request')->willThrowException(new \RuntimeException('upstream down'));
		$factory = $this->createMock(SignDocsClientFactory::class);
		$factory->method('envelopesFor')->willReturn(new EnvelopesResource($http));

		$service = new SigningSessionService(
			$factory,
			$this->mapper,
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserSession::class),
			$this->createMock(ISystemTagManager::class),
			$this->tagObjectMapper,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(CredentialsService::class),
			$this->createMock(IClientService::class),
		);

		try {
			$service->cancelSigningFlow('env_1');
			self::fail('the upstream failure should surface');
		} catch (\RuntimeException) {
			self::assertSame('pending', $entity->getStatus(), 'must not claim cancelled');
		}
	}

	public function testCancellingRebadgesTheFile(): void {
		$this->given('ss_1', ['kind' => 'session']);
		$this->response = ['sessionId' => 'ss_1', 'status' => 'CANCELLED'];
		$this->tagObjectMapper->expects(self::once())
			->method('assignTags')
			->with('136', 'files', '33');

		$this->service->cancelSigningFlow('ss_1');
	}
}
