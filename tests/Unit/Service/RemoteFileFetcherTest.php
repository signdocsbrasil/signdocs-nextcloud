<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\Service\RemoteFileFetcher;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pinning down the URL-fetcher's safety net.
 *
 * SSRF protections are layered:
 *   1. Scheme allowlist (HTTPS only by default)
 *   2. NC's IClientService LocalServerException handling
 *   3. Content-Type allowlist (reject text/html, executables, archives)
 *   4. Size cap (reject anything > maxBytes)
 *
 * Each test pins one of those layers. A regression in any of them is
 * potentially a remote-fetch SSRF or a phishing-page-as-document smuggle.
 */
class RemoteFileFetcherTest extends TestCase {
	/** @var IClientService&MockObject */
	private $clientService;
	/** @var IClient&MockObject */
	private $client;

	private RemoteFileFetcher $fetcher;

	protected function setUp(): void {
		parent::setUp();
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($this->client);
		$this->fetcher = new RemoteFileFetcher($this->clientService);
	}

	public function testRejectsMalformedUrl(): void {
		$this->client->expects(self::never())->method('get');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('URL malformada.');
		$this->fetcher->fetch('not a url at all');
	}

	public function testRejectsHttpScheme(): void {
		// http:// is rejected by default (production); the SIGNDOCS_ALLOW_HTTP
		// env var is for the CI/dev paths only.
		putenv('SIGNDOCS_ALLOW_HTTP=');
		$this->client->expects(self::never())->method('get');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Apenas URLs HTTPS são aceitas.');
		$this->fetcher->fetch('http://example.com/contract.pdf');
	}

	public function testRejectsFileScheme(): void {
		$this->client->expects(self::never())->method('get');
		$this->expectException(\InvalidArgumentException::class);
		$this->fetcher->fetch('file:///etc/passwd');
	}

	public function testRejectsFtpScheme(): void {
		$this->client->expects(self::never())->method('get');
		$this->expectException(\InvalidArgumentException::class);
		$this->fetcher->fetch('ftp://files.example.com/contract.pdf');
	}

	public function testTranslatesLocalServerExceptionToInvalidArgument(): void {
		// NC's IClientService throws LocalServerException when the URL
		// resolves to a blocked internal address. We translate that to
		// InvalidArgument so the controller surfaces 422, not 500.
		$this->client->method('get')
			->willThrowException(new LocalServerException('blocked'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('URLs internas');
		$this->fetcher->fetch('https://localhost/contract.pdf');
	}

	public function testRejectsDisallowedContentType(): void {
		$response = $this->createResponseMock(
			body: '<html><body>phishing landing page</body></html>',
			contentType: 'text/html; charset=utf-8',
		);
		$this->client->method('get')->willReturn($response);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Tipo de arquivo "text/html" não suportado.');
		$this->fetcher->fetch('https://attacker.example.com/fake-contract.pdf');
	}

	public function testRejectsExecutableContentType(): void {
		$response = $this->createResponseMock('MZ...', 'application/x-msdownload');
		$this->client->method('get')->willReturn($response);

		$this->expectException(\InvalidArgumentException::class);
		$this->fetcher->fetch('https://attacker.example.com/contract.pdf');
	}

	public function testRejectsZipContentType(): void {
		$response = $this->createResponseMock('PK...', 'application/zip');
		$this->client->method('get')->willReturn($response);

		$this->expectException(\InvalidArgumentException::class);
		$this->fetcher->fetch('https://attacker.example.com/contract.pdf');
	}

	public function testRejectsOversizedResponse(): void {
		// 11 bytes of body, max 10 → must reject before we land it on disk.
		$response = $this->createResponseMock(str_repeat('x', 11), 'application/pdf');
		$this->client->method('get')->willReturn($response);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('excede o limite');
		$this->fetcher->fetch('https://example.com/contract.pdf', maxBytes: 10);
	}

	public function testRejectsEmptyBody(): void {
		$response = $this->createResponseMock('', 'application/pdf');
		$this->client->method('get')->willReturn($response);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('arquivo vazio');
		$this->fetcher->fetch('https://example.com/empty.pdf');
	}

	public function testHappyPathPdfReturnsContentAndSuggestedFilename(): void {
		$response = $this->createResponseMock('%PDF-1.4 ...', 'application/pdf');
		$this->client->method('get')->willReturn($response);

		$result = $this->fetcher->fetch('https://example.com/path/to/contrato.pdf');

		self::assertSame('%PDF-1.4 ...', $result['content']);
		self::assertSame('application/pdf', $result['mimeType']);
		self::assertSame('contrato.pdf', $result['suggestedFilename']);
	}

	public function testFallsBackToGeneratedNameWhenUrlPathIsEmpty(): void {
		$response = $this->createResponseMock('%PDF-1.4 ...', 'application/pdf');
		$this->client->method('get')->willReturn($response);

		$result = $this->fetcher->fetch('https://example.com/');

		self::assertStringStartsWith('documento-remoto-', $result['suggestedFilename']);
		self::assertStringEndsWith('.pdf', $result['suggestedFilename']);
	}

	public function testStripsContentTypeParameters(): void {
		// "application/pdf; charset=binary" should still be accepted.
		$response = $this->createResponseMock('%PDF-1.4 ...', 'application/pdf; charset=binary');
		$this->client->method('get')->willReturn($response);

		$result = $this->fetcher->fetch('https://example.com/contract.pdf');
		self::assertSame('application/pdf', $result['mimeType']);
	}

	public function testHttpAllowedWhenEnvVarSet(): void {
		// Dev/CI flow — explicit opt-in via env var.
		putenv('SIGNDOCS_ALLOW_HTTP=1');
		try {
			$response = $this->createResponseMock('%PDF-1.4 ...', 'application/pdf');
			$this->client->method('get')->willReturn($response);

			$result = $this->fetcher->fetch('http://localhost:8080/contract.pdf');
			self::assertSame('application/pdf', $result['mimeType']);
		} finally {
			putenv('SIGNDOCS_ALLOW_HTTP=');
		}
	}

	private function createResponseMock(string $body, string $contentType): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')
			->with('Content-Type')
			->willReturn($contentType);
		return $response;
	}
}
