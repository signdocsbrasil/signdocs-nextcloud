<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use SignDocsBrasil\Api\HttpClient;
use SignDocsBrasil\Api\Resources\EnvelopesResource;
use SignDocsBrasil\Api\SignDocsBrasilClient;

/**
 * Idempotency keys on the multi-signer path.
 *
 * The SDK mints a key per call, which covers its own retries of 429/500/503.
 * It cannot cover a second invocation — a double-clicked confirm, or a user
 * retrying after a PHP timeout — because each invocation mints a fresh key and
 * so buys another envelope, another quota charge and another round of
 * invitations. The dialog sends one request id per submission and this class
 * derives the per-call keys from it.
 *
 * The derivation has to keep the signers apart. The API scopes its cache by
 * key and resolved path, and every signer on an envelope shares one path, so a
 * single key for the whole envelope would serve signer 2 the response cached
 * for signer 1 — and that response carries the only copy of signer 1's
 * clientSecret.
 */
class EnvelopeIdempotencyKeysTest extends TestCase {
	private SigningSessionService $service;

	/** @var array<int, array{path: string, key: ?string}> */
	private array $keyed = [];

	private const SIGNERS = [
		['name' => 'Maria Silva', 'email' => 'maria@example.com', 'cpf' => '52998224725'],
		['name' => 'Joao Souza', 'email' => 'joao@example.com', 'cpf' => '11144477735'],
	];

	protected function setUp(): void {
		parent::setUp();

		$http = $this->createMock(HttpClient::class);
		// Every create on this path routes through requestWithIdempotency, so
		// the key is a positional argument here rather than a header.
		$http->method('requestWithIdempotency')->willReturnCallback(
			function (string $method, string $path, $body = null, ?string $idempotencyKey = null) {
				$this->keyed[] = ['path' => $path, 'key' => $idempotencyKey];
				return $this->responseFor($path);
			},
		);

		$clientFactory = $this->createMock(SignDocsClientFactory::class);
		$clientFactory->method('forCurrentUser')->willReturn(
			$this->clientWithEnvelopes(new EnvelopesResource($http)),
		);

		$mapper = $this->createMock(SigningSessionMapper::class);
		$mapper->method('insert')->willReturnArgument(0);

		$tagManager = $this->createMock(ISystemTagManager::class);
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn('33');
		$tagManager->method('getAllTags')->willReturn([$tag]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1785350000);

		$this->service = new SigningSessionService(
			$clientFactory,
			$mapper,
			$this->rootFolderWithPdf(),
			$this->userSession(),
			$tagManager,
			$this->createMock(ISystemTagObjectMapper::class),
			$time,
			$this->createMock(LoggerInterface::class),
			$this->createMock(CredentialsService::class),
			$this->createMock(IClientService::class),
		);
	}

	/** @return array<string, mixed> */
	private function responseFor(string $path): array {
		if (str_ends_with($path, '/sessions')) {
			// One session id per signer so the mirror row is distinguishable.
			$n = count($this->keyed);
			return [
				'sessionId' => 'ss_' . $n,
				'transactionId' => 'tx_' . $n,
				'signerIndex' => $n,
				'status' => 'ACTIVE',
				'url' => 'https://sign.example/s/ss_' . $n,
				'clientSecret' => 'ss_secret_' . $n,
				'expiresAt' => '2026-09-01T00:00:00.000Z',
			];
		}
		return [
			'envelopeId' => 'env_1',
			'status' => 'CREATED',
			'signingMode' => 'PARALLEL',
			'totalSigners' => 2,
			'createdAt' => '2026-08-20T00:00:00.000Z',
			'expiresAt' => '2026-09-01T00:00:00.000Z',
		];
	}

	/** SignDocsBrasilClient is final; build one without running __construct. */
	private function clientWithEnvelopes(EnvelopesResource $envelopes): SignDocsBrasilClient {
		$ref = new ReflectionClass(SignDocsBrasilClient::class);
		$client = $ref->newInstanceWithoutConstructor();
		$prop = $ref->getProperty('envelopes');
		$prop->setAccessible(true);
		$prop->setValue($client, $envelopes);
		return $client;
	}

	private function rootFolderWithPdf(): IRootFolder {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
		$file->method('getName')->willReturn('contrato.pdf');

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$file]);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		return $root;
	}

	private function userSession(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('getEMailAddress')->willReturn('owner@example.com');
		$user->method('getDisplayName')->willReturn('Admin');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	/** @return list<string> the key sent with each add-session call, in order */
	private function signerKeys(): array {
		return array_values(array_map(
			static fn (array $c): string => (string)$c['key'],
			array_filter($this->keyed, static fn (array $c): bool => str_ends_with($c['path'], '/sessions')),
		));
	}

	public function testEachSignerGetsADistinctKeyDerivedFromTheRequestId(): void {
		$this->service->createForFile(136, self::SIGNERS, [
			'mode' => 'electronic',
			'order' => 'PARALLEL',
			'requestId' => 'b6f1c0de-1234-4abc-9def-0123456789ab',
		]);

		$keys = $this->signerKeys();
		self::assertCount(2, $keys);
		self::assertSame(count($keys), count(array_unique($keys)), 'one key per signer, never shared');
		self::assertStringContainsString('b6f1c0de-1234-4abc-9def-0123456789ab', $keys[0]);
		self::assertStringEndsWith('#signer#0', $keys[0]);
		self::assertStringEndsWith('#signer#1', $keys[1]);
	}

	public function testTheEnvelopeItselfIsKeyedApartFromItsSigners(): void {
		$this->service->createForFile(136, self::SIGNERS, [
			'mode' => 'electronic',
			'order' => 'PARALLEL',
			'requestId' => 'b6f1c0de-1234-4abc-9def-0123456789ab',
		]);

		$envelopeKey = $this->keyed[0]['key'];
		self::assertStringEndsWith('#envelope', (string)$envelopeKey);
		self::assertNotContains($envelopeKey, $this->signerKeys());
	}

	public function testTheSameSubmissionRepeatedSendsTheSameKeys(): void {
		$options = [
			'mode' => 'electronic',
			'order' => 'PARALLEL',
			'requestId' => 'b6f1c0de-1234-4abc-9def-0123456789ab',
		];

		$this->service->createForFile(136, self::SIGNERS, $options);
		$first = $this->keyed;
		$this->keyed = [];
		$this->service->createForFile(136, self::SIGNERS, $options);

		// The retry has to land on the API's cache. Identical keys are what put
		// it there; anything else is a second envelope and a second charge.
		self::assertSame(
			array_column($first, 'key'),
			array_column($this->keyed, 'key'),
		);
	}

	public function testANewSubmissionSendsDifferentKeys(): void {
		$base = ['mode' => 'electronic', 'order' => 'PARALLEL'];

		$this->service->createForFile(136, self::SIGNERS, $base + ['requestId' => 'aaaaaaaa-1111-4aaa-9aaa-aaaaaaaaaaaa']);
		$first = array_column($this->keyed, 'key');
		$this->keyed = [];
		$this->service->createForFile(136, self::SIGNERS, $base + ['requestId' => 'bbbbbbbb-2222-4bbb-9bbb-bbbbbbbbbbbb']);

		// Sending the same document again on purpose must still work.
		self::assertNotSame($first, array_column($this->keyed, 'key'));
	}

	public function testNoRequestIdLeavesTheSdkToMintItsOwnKey(): void {
		// Older bundles, and any caller that posts straight to the controller,
		// send nothing. That must degrade to today's behaviour, not fail.
		$this->service->createForFile(136, self::SIGNERS, ['mode' => 'electronic', 'order' => 'PARALLEL']);

		foreach ($this->keyed as $call) {
			self::assertNull($call['key'], 'the SDK mints one per call when we pass none');
		}
	}

	public function testAMalformedRequestIdIsDroppedRatherThanForwarded(): void {
		// The value becomes part of a sort key upstream, and '#' is the
		// separator this class appends suffixes with. A bad one costs the
		// cross-invocation guarantee, not the signature.
		$this->service->createForFile(136, self::SIGNERS, [
			'mode' => 'electronic',
			'order' => 'PARALLEL',
			'requestId' => 'no#separators#allowed',
		]);

		foreach ($this->keyed as $call) {
			self::assertNull($call['key']);
		}
	}
}
