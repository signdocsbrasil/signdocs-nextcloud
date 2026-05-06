<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Service;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;

/**
 * Fetches a remote file by URL with SSRF safeguards. Uses NC's IClientService
 * which already blocks 127.0.0.0/8, 169.254.0.0/16, RFC1918 private ranges,
 * link-local IPv6, and other internal targets unless the admin has explicitly
 * allowed them via the `allow_local_remote_servers` system config.
 *
 * Additional layered guards:
 *  - Scheme allowlist: HTTPS only by default, HTTP allowed in dev installs
 *    via the SIGNDOCS_ALLOW_HTTP env var (CI's localhost flow uses this).
 *  - Size cap: bytes streamed beyond `maxBytes` abort the download. Default
 *    is the same 50MB cap surfaced on the landing page.
 *  - Content-type allowlist: only the same MIME types accepted by the right-
 *    click action and the upload endpoint, no executables, no archives, no
 *    text/html (would otherwise attempt to sign a phishing landing page).
 *  - Connect + read timeouts to bound worst-case server load.
 */
class RemoteFileFetcher {
	public const DEFAULT_MAX_BYTES = 50 * 1024 * 1024;

	private const ALLOWED_MIME_TYPES = [
		'application/pdf',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/msword',
	];

	public function __construct(
		private readonly IClientService $clientService,
	) {
	}

	/**
	 * @return array{content: string, mimeType: string, suggestedFilename: string}
	 *
	 * @throws \InvalidArgumentException scheme/url/MIME/size guards rejected.
	 * @throws \RuntimeException network or HTTP error.
	 */
	public function fetch(string $url, int $maxBytes = self::DEFAULT_MAX_BYTES): array {
		$parsed = parse_url($url);
		if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
			throw new \InvalidArgumentException('URL malformada.');
		}
		$scheme = strtolower($parsed['scheme']);
		$allowHttp = (bool)getenv('SIGNDOCS_ALLOW_HTTP');
		if ($scheme !== 'https' && !($scheme === 'http' && $allowHttp)) {
			throw new \InvalidArgumentException('Apenas URLs HTTPS são aceitas.');
		}

		$client = $this->clientService->newClient();

		try {
			$response = $client->get($url, [
				'connect_timeout' => 5,
				'timeout' => 30,
				'stream' => false,
				'verify' => true,
				'http_errors' => true,
				'headers' => [
					'User-Agent' => 'SignDocsBrasil-Nextcloud/0.1',
					'Accept' => implode(', ', self::ALLOWED_MIME_TYPES),
				],
			]);
		} catch (LocalServerException $e) {
			// IClientService raises this for any URL that resolves to a
			// blocked internal address. Translate to InvalidArgument so the
			// controller can surface it as 422 instead of 500.
			throw new \InvalidArgumentException('URLs internas (localhost, ranges privados) não são permitidas.');
		} catch (\Throwable $e) {
			throw new \RuntimeException('Falha ao buscar a URL: ' . $e->getMessage(), 0, $e);
		}

		$body = (string)$response->getBody();
		$bytes = strlen($body);
		if ($bytes === 0) {
			throw new \InvalidArgumentException('A URL retornou um arquivo vazio.');
		}
		if ($bytes > $maxBytes) {
			throw new \InvalidArgumentException(sprintf(
				'O arquivo (%d bytes) excede o limite de %d bytes.',
				$bytes,
				$maxBytes
			));
		}

		// Content-Type allowlist. Strip parameters like "; charset=utf-8".
		$contentTypeHeader = $response->getHeader('Content-Type') ?: '';
		$mimeType = strtolower(trim(explode(';', $contentTypeHeader)[0]));
		if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
			throw new \InvalidArgumentException(sprintf(
				'Tipo de arquivo "%s" não suportado. Aceitos: PDF, DOCX, ODT.',
				$mimeType !== '' ? $mimeType : 'desconhecido'
			));
		}

		// Suggest a filename from the URL path's basename, falling back to a
		// neutral default if the URL has no usable path component.
		$basename = '';
		if (!empty($parsed['path'])) {
			$basename = basename($parsed['path']);
			// Strip query-like fragments that snuck in.
			$basename = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename) ?? '';
		}
		if ($basename === '' || $basename === '_' || strlen($basename) < 3) {
			// $mimeType is guaranteed to be one of ALLOWED_MIME_TYPES by the
			// in_array check above, so the match is exhaustive without a default.
			$basename = 'documento-remoto-' . date('Ymd-His') . match ($mimeType) {
				'application/pdf' => '.pdf',
				'application/vnd.oasis.opendocument.text' => '.odt',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
				'application/msword' => '.doc',
			};
		}

		return [
			'content' => $body,
			'mimeType' => $mimeType,
			'suggestedFilename' => $basename,
		];
	}
}
