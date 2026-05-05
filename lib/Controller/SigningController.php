<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Controller;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\NotConnectedException;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SigningController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly SigningSessionService $service,
		private readonly SigningSessionMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @NoAdminRequired
	 */
	public function create(int $fileId, array $signers, array $options = []): DataResponse {
		try {
			$session = $this->service->createForFile($fileId, $signers, $options);
		} catch (NotConnectedException $e) {
			return new DataResponse(
				['error' => 'not_connected', 'message' => $e->getMessage()],
				Http::STATUS_PRECONDITION_FAILED
			);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to create signing session', [
				'exception' => $e,
				'fileId' => $fileId,
			]);
			return new DataResponse(
				['error' => 'create_failed', 'message' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new DataResponse([
			'sessionId' => $session->getSessionId(),
			'status' => $session->getStatus(),
			'metadata' => $session->getMetadata() ? json_decode($session->getMetadata(), true) : null,
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function listForUser(int $limit = 50, int $offset = 0): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$entities = $this->mapper->findByUser($user->getUID(), $limit, $offset);
		return new DataResponse(array_map(static fn ($e) => [
			'sessionId' => $e->getSessionId(),
			'fileId' => $e->getFileId(),
			'status' => $e->getStatus(),
			'createdAt' => $e->getCreatedAt(),
			'updatedAt' => $e->getUpdatedAt(),
			'signedFileId' => $e->getSignedFileId(),
		], $entities));
	}

	/**
	 * @NoAdminRequired
	 */
	public function listForFile(int $fileId): DataResponse {
		$entities = $this->mapper->findByFile($fileId);
		return new DataResponse(array_map(static fn ($e) => [
			'sessionId' => $e->getSessionId(),
			'status' => $e->getStatus(),
			'createdAt' => $e->getCreatedAt(),
			'updatedAt' => $e->getUpdatedAt(),
		], $entities));
	}
}
