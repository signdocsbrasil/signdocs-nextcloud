<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Controller;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\NotConnectedException;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
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
		} catch (\InvalidArgumentException $e) {
			// Constraint-violation rejections from the service (e.g. ICP
			// digital-certificate + parallel order) — surface as 422 with a
			// machine-readable error code so the front-end can localize the
			// message and the SignDocs API never sees a doomed request.
			return new DataResponse(
				['error' => 'invalid_input', 'message' => $e->getMessage()],
				Http::STATUS_UNPROCESSABLE_ENTITY
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

	/**
	 * Cancel a signing flow. Works for both single-signer sessions and
	 * envelopes, where every member session is cancelled.
	 *
	 * Ownership is enforced here: a user may only cancel their own rows, since
	 * the id alone is guessable enough to be worth checking.
	 *
	 * @NoAdminRequired
	 */
	public function cancel(string $sessionId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$entity = $this->mapper->findBySessionId($sessionId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		if ($entity->getUserId() !== $user->getUID()) {
			return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}
		if (in_array($entity->getStatus(), ['completed', 'cancelled'], true)) {
			// Already terminal — cancelling a signed document is meaningless and
			// re-cancelling is a no-op worth reporting as a conflict.
			return new DataResponse(
				['error' => 'not_cancellable', 'status' => $entity->getStatus()],
				Http::STATUS_CONFLICT,
			);
		}

		try {
			$result = $this->service->cancelSigningFlow($sessionId);
		} catch (NotConnectedException $e) {
			return new DataResponse(
				['error' => 'not_connected', 'message' => $e->getMessage()],
				Http::STATUS_PRECONDITION_FAILED,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to cancel signing flow', ['exception' => $e]);
			return new DataResponse(
				['error' => 'cancel_failed', 'message' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return new DataResponse([
			'sessionId' => $sessionId,
			'status' => 'cancelled',
			// How many pending signers were stopped, and how many signatures
			// already collected were left intact upstream.
			'cancelledCount' => $result['cancelled'],
			'preservedSignedCount' => $result['preservedSigned'],
			'alreadyCancelled' => $result['alreadyCancelled'],
		]);
	}
}
