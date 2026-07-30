<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\Db\SigningSession;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SignDocsBrasil\Api\HttpClient;
use SignDocsBrasil\Api\Resources\DocumentsResource;

/**
 * Save-back of the signed artifact, which has two shapes:
 *
 *  - PDF flow: the API stamps/signs the PDF itself, so one file comes back.
 *  - CAdES flow: a non-PDF signed with an ICP-Brasil certificate gets a
 *    *detached* signature, so the document and its .p7s are saved as a pair —
 *    the same two-file result the Google Drive add-on produces.
 *
 * A non-PDF signed without a certificate has no signed artifact upstream at all
 * (the API stores it as documentFormat=generic and only the digital-certificate
 * step ever writes a .p7s), so the job must not sit there polling for it.
 */
class SaveSignedDocumentTest extends TestCase {
	private SignDocsClientFactory $clientFactory;
	private SigningSessionMapper $mapper;
	private IRootFolder $rootFolder;
	private IClientService $clientService;
	private CredentialsService $credentials;
	private LoggerInterface $logger;
	private SigningSessionService $service;

	/** @var Folder&\PHPUnit\Framework\MockObject\MockObject */
	private $signedFolder;
	/** @var array<int, array{name: string, content: string}> */
	private array $written = [];

	protected function setUp(): void {
		parent::setUp();

		$this->clientFactory = $this->createMock(SignDocsClientFactory::class);
		$this->mapper = $this->createMock(SigningSessionMapper::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->credentials = $this->createMock(CredentialsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1785350000);

		$this->service = new SigningSessionService(
			$this->clientFactory,
			$this->mapper,
			$this->rootFolder,
			$this->createMock(IUserSession::class),
			$this->createMock(\OCP\SystemTag\ISystemTagManager::class),
			$this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class),
			$time,
			$this->logger,
			$this->credentials,
			$this->clientService,
		);

		$this->credentials->method('getDefaultSignedFolder')->willReturn('Assinados');
	}

	/**
	 * Wire the user folder: original document `$originalName`, /Assinados target.
	 *
	 * @param (callable(string): string)|null $nameMangler how the target folder
	 *                                                     resolves collisions; null means every name is free.
	 */
	private function givenUserFolderWith(string $originalName, ?callable $nameMangler = null): void {
		$original = $this->createMock(File::class);
		$original->method('getName')->willReturn($originalName);

		$this->signedFolder = $this->createMock(Folder::class);
		$this->signedFolder->method('getNonExistingName')
			->willReturnCallback($nameMangler ?? static fn (string $name): string => $name);

		$nextId = 900;
		$this->signedFolder->method('newFile')
			->willReturnCallback(function (string $name, string $content) use (&$nextId) {
				$this->written[] = ['name' => $name, 'content' => $content];
				$node = $this->createMock(File::class);
				$node->method('getId')->willReturn(++$nextId);
				$node->method('getPath')->willReturn('/admin/files/Assinados/' . $name);
				return $node;
			});

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$original]);
		$userFolder->method('nodeExists')->with('/Assinados')->willReturn(true);
		$userFolder->method('get')->with('/Assinados')->willReturn($this->signedFolder);

		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
	}

	/**
	 * Stub GET /v1/transactions/{id}/download with the given payload.
	 *
	 * DocumentsResource is final, so it can't be mocked — instead a real one is
	 * built around a mocked HttpClient, which also exercises the SDK's own
	 * DownloadResponse parsing (the thing that used to drop signatureUrl).
	 */
	private function givenDownloadUrls(array $payload): void {
		$http = $this->createMock(HttpClient::class);
		$http->method('request')->willReturn($payload);
		$this->clientFactory->method('documentsFor')->willReturn(new DocumentsResource($http));
	}

	/** Every presigned URL resolves to `$byUrl[$url]`. */
	private function givenRemoteBodies(array $byUrl): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($byUrl) {
			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn($byUrl[$url] ?? '');
			return $response;
		});
		$this->clientService->method('newClient')->willReturn($client);
	}

	private function session(array $metadata, ?string $transactionId = 'tx_1'): SigningSession {
		$entity = new SigningSession();
		$entity->setSessionId('ss_1');
		$entity->setTransactionId($transactionId);
		$entity->setFileId(136);
		$entity->setUserId('admin');
		$entity->setStatus('completed');
		$entity->setMetadata(json_encode($metadata, JSON_THROW_ON_ERROR));
		return $entity;
	}

	public function testDocxSignedWithCertificateSavesDocumentAndDetachedSignature(): void {
		$this->givenUserFolderWith('Contrato.docx');
		$this->givenDownloadUrls([
			'originalUrl' => 'https://s3.example/original.docx',
			'signatureUrl' => 'https://s3.example/signature.p7s',
		]);
		$this->givenRemoteBodies([
			'https://s3.example/original.docx' => "PK\x03\x04docx-bytes",
			'https://s3.example/signature.p7s' => "\x30\x82cms-bytes",
		]);

		$entity = $this->session(['kind' => 'session', 'mode' => 'digital_certificate']);
		$this->mapper->expects(self::once())->method('update');

		$this->service->saveSignedDocument($entity);

		self::assertSame(
			['Contrato-assinado.docx', 'Contrato-assinado.p7s'],
			array_column($this->written, 'name'),
			'native document first, detached signature beside it',
		);
		self::assertSame("PK\x03\x04docx-bytes", $this->written[0]['content']);
		self::assertSame("\x30\x82cms-bytes", $this->written[1]['content']);

		// The document is what the row points at; the .p7s is recorded alongside.
		self::assertSame('901', $entity->getSignedFileId());
		$meta = json_decode((string)$entity->getMetadata(), true);
		self::assertSame(['902'], $meta['extraFileIds']);
	}

	public function testDetachedPairKeepsAMatchingBasenameOnCollision(): void {
		$this->givenUserFolderWith(
			'Contrato.docx',
			static fn (string $name): string => str_replace('-assinado.', '-assinado (2).', $name),
		);
		$this->givenDownloadUrls([
			'originalUrl' => 'https://s3.example/original.docx',
			'signatureUrl' => 'https://s3.example/signature.p7s',
		]);
		$this->givenRemoteBodies([
			'https://s3.example/original.docx' => "PK\x03\x04docx",
			'https://s3.example/signature.p7s' => "\x30\x82cms",
		]);

		$this->service->saveSignedDocument($this->session(['kind' => 'session', 'mode' => 'icp_a1']));

		self::assertSame(
			['Contrato-assinado (2).docx', 'Contrato-assinado (2).p7s'],
			array_column($this->written, 'name'),
		);
	}

	public function testPdfFlowStillSavesASingleSignedPdf(): void {
		$this->givenUserFolderWith('Contrato.pdf');
		$this->givenDownloadUrls([
			'originalUrl' => 'https://s3.example/original.pdf',
			'signedUrl' => 'https://s3.example/signed.pdf',
		]);
		$this->givenRemoteBodies(['https://s3.example/signed.pdf' => '%PDF-1.7 signed']);

		$entity = $this->session(['kind' => 'session', 'mode' => 'electronic']);
		$this->service->saveSignedDocument($entity);

		self::assertSame(['Contrato-assinado.pdf'], array_column($this->written, 'name'));
		$meta = json_decode((string)$entity->getMetadata(), true);
		self::assertArrayNotHasKey('extraFileIds', $meta);
	}

	public function testNonPdfWithoutCertificateIsMarkedUnsaveable(): void {
		// documentFormat=generic with a click/OTP policy: the API has no artifact
		// and never will. One check, then the row is marked so the job stops
		// asking. Format comes from the API, not the filename — content sniffing
		// upstream is the authority on what was actually uploaded.
		//
		// Note the response still carries a signatureUrl even though no .p7s was
		// ever written: the API presigns that S3 key without checking existence,
		// so the URL 404s on GET. Observed against HML — the decision has to come
		// from the policy, not from the URL being present.
		$this->givenUserFolderWith('Contrato.docx');
		$this->givenDownloadUrls([
			'documentFormat' => 'generic',
			'originalUrl' => 'https://s3.example/original.docx',
			'signatureUrl' => 'https://s3.example/does-not-exist.p7s',
		]);
		$this->givenRemoteBodies([]);

		$entity = $this->session(['kind' => 'session', 'mode' => 'click_plus_otp']);
		$this->mapper->expects(self::once())->method('update');

		$this->service->saveSignedDocument($entity);

		self::assertSame([], $this->written);
		self::assertNull($entity->getSignedFileId());
		$meta = json_decode((string)$entity->getMetadata(), true);
		self::assertTrue($meta['noSignedArtifact']);
	}

	public function testLegacyGenericRowCountsAttemptsThenGivesUp(): void {
		// No recorded policy, so we can't tell a slow .p7s from one that will
		// never exist. Count attempts and stop after the cap instead of logging a
		// failed fetch every 5 minutes forever.
		$this->givenUserFolderWith('Contrato.docx');
		$this->givenDownloadUrls([
			'documentFormat' => 'generic',
			'signatureUrl' => 'https://s3.example/does-not-exist.p7s',
		]);
		$this->givenRemoteBodies([]);

		$entity = $this->session(['kind' => 'session']);
		$this->service->saveSignedDocument($entity);
		$meta = json_decode((string)$entity->getMetadata(), true);
		self::assertSame(1, $meta['artifactAttempts']);
		self::assertArrayNotHasKey('noSignedArtifact', $meta);

		$entity = $this->session(['kind' => 'session', 'artifactAttempts' => 11]);
		$this->service->saveSignedDocument($entity);
		$meta = json_decode((string)$entity->getMetadata(), true);
		self::assertTrue($meta['noSignedArtifact'], 'gives up at the cap');
	}

	public function testAMarkedRowIsNotPolledAgain(): void {
		$this->givenUserFolderWith('Contrato.docx');
		$this->clientFactory->expects(self::never())->method('documentsFor');

		$this->service->saveSignedDocument($this->session([
			'kind' => 'session',
			'mode' => 'click_plus_otp',
			'noSignedArtifact' => true,
		]));

		self::assertSame([], $this->written);
	}

	public function testAPdfStillPendingIsNotMarkedUnsaveable(): void {
		// documentFormat=pdf with nothing ready yet must stay in the retry set.
		$this->givenUserFolderWith('Contrato.pdf');
		$this->givenDownloadUrls(['documentFormat' => 'pdf']);
		$this->givenRemoteBodies([]);

		$entity = $this->session(['kind' => 'session', 'mode' => 'electronic']);
		$this->mapper->expects(self::never())->method('update');

		$this->service->saveSignedDocument($entity);

		$meta = json_decode((string)$entity->getMetadata(), true);
		self::assertArrayNotHasKey('noSignedArtifact', $meta);
	}

	public function testGenericWithCertificatePendingIsNotMarkedUnsaveable(): void {
		// The .p7s can still show up on the certificate path, so keep retrying.
		$this->givenUserFolderWith('Contrato.docx');
		$this->givenDownloadUrls(['documentFormat' => 'generic']);
		$this->givenRemoteBodies([]);

		$entity = $this->session(['kind' => 'session', 'mode' => 'digital_certificate']);
		$this->mapper->expects(self::never())->method('update');

		$this->service->saveSignedDocument($entity);

		$meta = json_decode((string)$entity->getMetadata(), true);
		self::assertArrayNotHasKey('noSignedArtifact', $meta);
	}

	public function testLegacyRowWithoutARecordedModeStillAsksTheApi(): void {
		// Rows created before `mode` was persisted must keep working.
		$this->givenUserFolderWith('Contrato.docx');
		$this->givenDownloadUrls(['signatureUrl' => 'https://s3.example/signature.p7s']);
		$this->givenRemoteBodies(['https://s3.example/signature.p7s' => "\x30\x82cms"]);

		$this->service->saveSignedDocument($this->session(['kind' => 'session']));

		self::assertSame(['Contrato-assinado.p7s'], array_column($this->written, 'name'));
	}

	public function testNothingIsSavedWhileTheArtifactIsStillMissing(): void {
		$this->givenUserFolderWith('Contrato.pdf');
		$this->givenDownloadUrls(['originalUrl' => 'https://s3.example/original.pdf']);
		$this->givenRemoteBodies([]);

		$entity = $this->session(['kind' => 'session', 'mode' => 'electronic']);
		$this->mapper->expects(self::never())->method('update');

		$this->service->saveSignedDocument($entity);

		self::assertSame([], $this->written);
		self::assertNull($entity->getSignedFileId());
	}

	public function testAnErrorPageIsNotMistakenForASignature(): void {
		$this->givenUserFolderWith('Contrato.docx');
		$this->givenDownloadUrls(['signatureUrl' => 'https://s3.example/signature.p7s']);
		$this->givenRemoteBodies(['https://s3.example/signature.p7s' => '<?xml version="1.0"?><Error/>']);

		$entity = $this->session(['kind' => 'session', 'mode' => 'digital_certificate']);
		$this->service->saveSignedDocument($entity);

		self::assertSame([], $this->written);
		self::assertNull($entity->getSignedFileId());
	}

	public function testAlreadySavedSessionIsLeftAlone(): void {
		$entity = $this->session(['kind' => 'session', 'mode' => 'digital_certificate']);
		$entity->setSignedFileId('555');
		$this->clientFactory->expects(self::never())->method('documentsFor');

		$this->service->saveSignedDocument($entity);

		self::assertSame([], $this->written);
	}

	public function testMissingTransactionIdIsReportedNotFetched(): void {
		$this->givenUserFolderWith('Contrato.docx');
		$this->clientFactory->expects(self::never())->method('documentsFor');
		$this->logger->expects(self::atLeastOnce())->method('warning');

		$this->service->saveSignedDocument(
			$this->session(['kind' => 'session', 'mode' => 'digital_certificate'], null),
		);

		self::assertSame([], $this->written);
	}
}
