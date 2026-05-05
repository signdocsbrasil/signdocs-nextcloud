<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Service;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Db\SigningSession;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;
use SignDocsBrasil\Api\Models\AddEnvelopeSessionRequest;
use SignDocsBrasil\Api\Models\CreateEnvelopeRequest;
use SignDocsBrasil\Api\Models\CreateSigningSessionRequest;
use SignDocsBrasil\Api\Models\Policy;
use SignDocsBrasil\Api\Models\Signer;

/**
 * Orchestrates: read NC file → create signing flow on SignDocs → mirror state →
 * tag the file with status.
 *
 * Single signer → SigningSessionsResource::create()
 * 2+ signers   → EnvelopesResource::create() + addSession() per signer
 *
 * The persisted `session_id` for multi-signer flows is the envelope id; the
 * stored metadata holds the per-signer session ids and share URLs (built as
 * `{url}?cs={clientSecret}` per the SignDocs URL assembly contract).
 */
class SigningSessionService {
	public function __construct(
		private readonly SignDocsClientFactory $clientFactory,
		private readonly SigningSessionMapper $mapper,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly ISystemTagManager $tagManager,
		private readonly ISystemTagObjectMapper $tagObjectMapper,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array{name: string, email: string, cpf?: string, phone?: string}[] $signers
	 * @param array{mode?: string, order?: string, validityDays?: int} $options
	 */
	public function createForFile(int $fileId, array $signers, array $options = []): SigningSession {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No active user session.');
		}
		$userId = $user->getUID();

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		if (empty($nodes)) {
			throw new NotFoundException('File not found in user storage: ' . $fileId);
		}
		$file = $nodes[0];
		$documentInline = [
			'content' => base64_encode($file->getContent()),
			'filename' => $file->getName(),
		];

		$client = $this->clientFactory->forCurrentUser();
		$policy = new Policy(profile: $this->mapModeToProfile($options['mode'] ?? 'electronic'));
		$expiresInMinutes = isset($options['validityDays']) ? max(5, (int) $options['validityDays'] * 1440) : null;
		$metadata = [
			'source' => 'nextcloud',
			'nc_file_id' => (string) $fileId,
			'nc_user_id' => $userId,
		];

		if (count($signers) <= 1) {
			return $this->createSingle($client, $policy, $signers[0] ?? null, $documentInline, $expiresInMinutes, $metadata, $fileId, $userId);
		}

		return $this->createEnvelope($client, $policy, $signers, $documentInline, $options['order'] ?? 'PARALLEL', $expiresInMinutes, $metadata, $fileId, $userId);
	}

	private function createSingle($client, Policy $policy, ?array $signerData, array $document, ?int $expiresInMinutes, array $metadata, int $fileId, string $userId): SigningSession {
		if ($signerData === null) {
			throw new \InvalidArgumentException('At least one signer is required.');
		}
		$request = new CreateSigningSessionRequest(
			purpose: 'DOCUMENT_SIGNATURE',
			policy: $policy,
			signer: $this->buildSigner($signerData, 0),
			document: $document,
			metadata: $metadata,
			locale: 'pt-BR',
			expiresInMinutes: $expiresInMinutes,
		);
		$apiSession = $client->signingSessions->create($request);

		return $this->persist(
			sessionId: $apiSession->sessionId,
			fileId: $fileId,
			userId: $userId,
			metadata: [
				'kind' => 'session',
				'transactionId' => $apiSession->transactionId,
				'signers' => [['email' => $signerData['email'] ?? null, 'name' => $signerData['name'] ?? null]],
			],
		);
	}

	private function createEnvelope($client, Policy $policy, array $signers, array $document, string $order, ?int $expiresInMinutes, array $metadata, int $fileId, string $userId): SigningSession {
		$envelope = $client->envelopes->create(new CreateEnvelopeRequest(
			signingMode: strtoupper($order) === 'SEQUENTIAL' ? 'SEQUENTIAL' : 'PARALLEL',
			totalSigners: count($signers),
			document: $document,
			metadata: $metadata,
			locale: 'pt-BR',
			expiresInMinutes: $expiresInMinutes,
		));

		$shareLinks = [];
		foreach (array_values($signers) as $i => $signerData) {
			$envSession = $client->envelopes->addSession($envelope->envelopeId, new AddEnvelopeSessionRequest(
				signer: $this->buildSigner($signerData, $i),
				policy: $policy,
				signerIndex: $i + 1,
				purpose: 'DOCUMENT_SIGNATURE',
			));
			$shareLinks[] = [
				'signerEmail' => $signerData['email'] ?? null,
				'signerName' => $signerData['name'] ?? null,
				'sessionId' => $envSession->sessionId,
				'url' => $envSession->url . '?cs=' . urlencode($envSession->clientSecret),
				'inviteSent' => $envSession->inviteSent,
			];
		}

		return $this->persist(
			sessionId: $envelope->envelopeId,
			fileId: $fileId,
			userId: $userId,
			metadata: [
				'kind' => 'envelope',
				'shareLinks' => $shareLinks,
			],
		);
	}

	/**
	 * Apply webhook-delivered status update.
	 */
	public function applyStatusUpdate(string $sessionId, string $status, ?string $signedFileId = null): void {
		try {
			$entity = $this->mapper->findBySessionId($sessionId);
		} catch (DoesNotExistException) {
			$this->logger->warning('Webhook for unknown SignDocs session', ['sessionId' => $sessionId]);
			return;
		}

		$entity->setStatus($status);
		$entity->setUpdatedAt($this->time->getTime());
		if ($signedFileId !== null) {
			$entity->setSignedFileId($signedFileId);
		}
		$this->mapper->update($entity);

		$tag = match (strtolower($status)) {
			'completed', 'signed', 'finalized' => Application::TAG_ASSINADO,
			'cancelled', 'rejected', 'expired' => Application::TAG_CANCELADO,
			default => Application::TAG_PENDENTE,
		};
		$this->applyStatusTag($entity->getFileId(), $tag);
	}

	private function buildSigner(array $signerData, int $index): Signer {
		// userExternalId is required by the SDK; use a stable value derived from
		// email (or fall back to an index-based id) so re-sends are idempotent.
		$externalId = isset($signerData['email']) && $signerData['email'] !== ''
			? 'nc:' . hash('sha256', strtolower($signerData['email']))
			: 'nc:idx:' . $index;
		return new Signer(
			name: $signerData['name'] ?? '',
			userExternalId: $externalId,
			email: $signerData['email'] ?? null,
			phone: $signerData['phone'] ?? null,
			cpf: $signerData['cpf'] ?? null,
		);
	}

	private function mapModeToProfile(string $mode): string {
		return match ($mode) {
			'icp_a1', 'icp_a3' => 'DIGITAL_CERTIFICATE',
			'biometric' => 'BIOMETRIC',
			default => 'CLICK_ONLY',
		};
	}

	private function persist(string $sessionId, int $fileId, string $userId, array $metadata): SigningSession {
		$now = $this->time->getTime();
		$entity = new SigningSession();
		$entity->setSessionId($sessionId);
		$entity->setUserId($userId);
		$entity->setFileId($fileId);
		$entity->setStatus('pending');
		$entity->setCreatedAt($now);
		$entity->setUpdatedAt($now);
		$entity->setMetadata(json_encode($metadata, JSON_THROW_ON_ERROR));
		$saved = $this->mapper->insert($entity);

		$this->applyStatusTag($fileId, Application::TAG_PENDENTE);
		return $saved;
	}

	private function applyStatusTag(int $fileId, string $tagName): void {
		try {
			$tags = $this->tagManager->getAllTags(null, $tagName);
			$tag = $tags[array_key_first($tags)] ?? $this->tagManager->createTag($tagName, true, false);
			$this->tagObjectMapper->assignTags((string) $fileId, 'files', $tag->getId());
		} catch (TagNotFoundException $e) {
			$this->logger->warning('Status tag missing, skipping', ['tag' => $tagName, 'exception' => $e]);
		}
	}
}
