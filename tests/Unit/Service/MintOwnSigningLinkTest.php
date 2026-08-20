<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\Db\SigningSession;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SignDocsBrasil\Api\HttpClient;
use SignDocsBrasil\Api\Resources\SigningSessionsResource;

/**
 * Minting your own signing link.
 *
 * This is the one path with no other way through. When you are a signer on
 * your own send SignDocs dispatches no invitation — the addresses match — so
 * the link exists only in the response to the create call, and closing that
 * dialog used to lose it for good.
 *
 * The suite drives a real SigningSessionsResource over a mocked HttpClient
 * rather than mocking the resource, so it fails if the bundled SDK does not
 * carry link() at all. That is not hypothetical: the feature shipped against a
 * constraint whose published release turned out not to contain the method, and
 * nothing here caught it because nothing exercised this path.
 */
class MintOwnSigningLinkTest extends TestCase {
	private SigningSessionMapper $mapper;
	private SigningSessionService $service;

	/** @var array<int, array{method: string, path: string}> */
	private array $calls = [];
	/** @var array<string, mixed> */
	private array $response = [];

	private const OWN_EMAIL = 'owner@example.com';

	protected function setUp(): void {
		parent::setUp();

		$http = $this->createMock(HttpClient::class);
		$http->method('request')->willReturnCallback(
			function (string $method, string $path) {
				$this->calls[] = ['method' => $method, 'path' => $path];
				return $this->response;
			},
		);

		$this->mapper = $this->createMock(SigningSessionMapper::class);

		$clientFactory = $this->createMock(SignDocsClientFactory::class);
		$clientFactory->method('signingSessionsFor')
			->willReturn(new SigningSessionsResource($http));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('getEMailAddress')->willReturn(self::OWN_EMAIL);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->service = new SigningSessionService(
			$clientFactory,
			$this->mapper,
			$this->createMock(IRootFolder::class),
			$userSession,
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ISystemTagObjectMapper::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(CredentialsService::class),
			$this->createMock(IClientService::class),
		);
	}

	/** @param array<string, mixed> $metadata */
	private function given(array $metadata, string $userId = 'admin'): void {
		$entity = new SigningSession();
		$entity->setSessionId('env_1');
		$entity->setFileId(136);
		$entity->setUserId($userId);
		$entity->setStatus('pending');
		$entity->setMetadata(json_encode($metadata, JSON_THROW_ON_ERROR));
		$this->mapper->method('findBySessionId')->willReturn($entity);
	}

	/** @return array<string, mixed> */
	private static function ownEntry(array $overrides = []): array {
		return array_merge([
			'sessionId' => 'ss_own',
			'signerEmail' => self::OWN_EMAIL,
			'shareable' => true,
		], $overrides);
	}

	private function minted(): void {
		$this->response = [
			'sessionId' => 'ss_own',
			'transactionId' => 'tx_1',
			'url' => 'https://sign.example/s/ss_own?cs=abc',
			'expiresAt' => '2026-08-27T12:00:00.000Z',
			'expiresIn' => 3600,
		];
	}

	public function testMintsThroughTheSdkLinkEndpoint(): void {
		$this->given(['kind' => 'envelope', 'shareLinks' => [self::ownEntry()]]);
		$this->minted();

		$result = $this->service->mintOwnSigningLink('env_1');

		self::assertCount(1, $this->calls);
		self::assertSame('POST', $this->calls[0]['method']);
		// The signer's own session, not the envelope row's id.
		self::assertSame('/v1/signing-sessions/ss_own/link', $this->calls[0]['path']);
		self::assertSame('https://sign.example/s/ss_own?cs=abc', $result['url']);
		self::assertSame('2026-08-27T12:00:00.000Z', $result['expiresAt']);
	}

	public function testRefusesARowBelongingToSomebodyElse(): void {
		// Indistinguishable from a missing row on purpose: this endpoint should
		// not confirm what exists.
		$this->given(['kind' => 'envelope', 'shareLinks' => [self::ownEntry()]], 'someone-else');

		$this->expectException(NotFoundException::class);
		$this->service->mintOwnSigningLink('env_1');
	}

	public function testRefusesWhenNoSignatureOnTheRowIsYours(): void {
		$this->given(['kind' => 'envelope', 'shareLinks' => [
			self::ownEntry(['signerEmail' => 'someone@example.com', 'sessionId' => 'ss_other']),
		]]);

		$this->expectException(NotFoundException::class);
		$this->service->mintOwnSigningLink('env_1');
		self::assertSame([], $this->calls, 'nothing may reach the API');
	}

	public function testRefusesAnEntryTheSharingRulesWithheld(): void {
		// The API authorises the tenant only and would happily mint this. The
		// check has to happen here or withholding the link is bypassable.
		$this->given(['kind' => 'envelope', 'shareLinks' => [
			self::ownEntry(['shareable' => false]),
		]]);

		$this->expectException(NotFoundException::class);
		$this->service->mintOwnSigningLink('env_1');
	}

	public function testTreatsALegacyRowWithNoShareableFlagAsWithheld(): void {
		// Rows written before the flag existed carry no opinion. Fail closed.
		$entry = self::ownEntry();
		unset($entry['shareable']);
		$this->given(['kind' => 'envelope', 'shareLinks' => [$entry]]);

		$this->expectException(NotFoundException::class);
		$this->service->mintOwnSigningLink('env_1');
	}

	public function testPropagatesAMissingRow(): void {
		$this->mapper->method('findBySessionId')
			->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(DoesNotExistException::class);
		$this->service->mintOwnSigningLink('env_missing');
	}
}
