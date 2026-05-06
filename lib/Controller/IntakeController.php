<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Controller;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Service\DocumentIntakeService;
use OCA\SignDocsBrasil\Service\RemoteFileFetcher;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Document intake — converts the two non-Files-app entry paths (local upload,
 * remote URL) into NC fileIds the existing SigningController flow can consume.
 *
 * Both endpoints land the file in `/SignDocs Brasil/Pendentes/` in the user's
 * Files. The same {fileId} response shape powers either intake path on the
 * front-end.
 */
class IntakeController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly DocumentIntakeService $intake,
		private readonly RemoteFileFetcher $remote,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @NoAdminRequired
	 */
	public function fromUpload(): DataResponse {
		$file = $this->request->getUploadedFile('file');
		if ($file === null || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(
				['error' => 'invalid_input', 'message' => 'Upload inválido ou ausente.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$bytes = file_get_contents($file['tmp_name']);
		if ($bytes === false) {
			return new DataResponse(
				['error' => 'read_failed', 'message' => 'Não foi possível ler o arquivo enviado.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		try {
			$saved = $this->intake->storeForCurrentUser($file['name'] ?? 'documento.pdf', $bytes);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to land uploaded document', ['exception' => $e]);
			return new DataResponse(
				['error' => 'store_failed', 'message' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new DataResponse($saved);
	}

	/**
	 * @NoAdminRequired
	 */
	public function fromUrl(string $url): DataResponse {
		try {
			$fetched = $this->remote->fetch($url);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(
				['error' => 'invalid_input', 'message' => $e->getMessage()],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to fetch URL for intake', [
				'exception' => $e,
				'url_host' => parse_url($url, PHP_URL_HOST),
			]);
			return new DataResponse(
				['error' => 'fetch_failed', 'message' => $e->getMessage()],
				Http::STATUS_BAD_GATEWAY
			);
		}

		try {
			$saved = $this->intake->storeForCurrentUser(
				$fetched['suggestedFilename'],
				$fetched['content']
			);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to land URL-fetched document', ['exception' => $e]);
			return new DataResponse(
				['error' => 'store_failed', 'message' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new DataResponse(array_merge($saved, [
			'sourceUrl' => $url,
			'mimeType' => $fetched['mimeType'],
		]));
	}
}
