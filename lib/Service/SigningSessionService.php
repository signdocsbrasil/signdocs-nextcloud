<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Service;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Db\SigningSession;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
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
		// Validate constraints BEFORE touching NC services or the SDK so an
		// invalid request fails fast and never reaches the SignDocs API.
		// Same constraints the API would enforce, surfaced as a 422 instead
		// of an opaque 4xx round-tripped through the SDK.
		$this->validateOptions(
			mode: $options['mode'] ?? 'electronic',
			orderUpper: strtoupper($options['order'] ?? 'PARALLEL'),
			signerCount: count($signers),
		);
		$this->validateSigners($signers);

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No active user session.');
		}
		$userId = $user->getUID();

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		if (empty($nodes) || !$nodes[0] instanceof File) {
			throw new NotFoundException('File not found in user storage: ' . $fileId);
		}
		$file = $nodes[0];
		$documentInline = [
			'content' => base64_encode($file->getContent()),
			'filename' => $file->getName(),
		];

		$client = $this->clientFactory->forCurrentUser();
		$policy = new Policy(profile: $this->mapModeToProfile($options['mode'] ?? 'electronic'));
		// Validity is server-side; the API picks a sensible default. Don't
		// surface validityDays from the UI even if it sneaks in.
		$metadata = [
			'source' => 'nextcloud',
			'nc_file_id' => (string)$fileId,
			'nc_user_id' => $userId,
		];

		if (count($signers) <= 1) {
			return $this->createSingle($client, $policy, $signers[0] ?? null, $documentInline, $metadata, $fileId, $userId);
		}

		return $this->createEnvelope($client, $policy, $signers, $documentInline, $options['order'] ?? 'PARALLEL', $metadata, $fileId, $userId);
	}

	private function createSingle($client, Policy $policy, ?array $signerData, array $document, array $metadata, int $fileId, string $userId): SigningSession {
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

	private function createEnvelope($client, Policy $policy, array $signers, array $document, string $order, array $metadata, int $fileId, string $userId): SigningSession {
		$envelope = $client->envelopes->create(new CreateEnvelopeRequest(
			signingMode: strtoupper($order) === 'SEQUENTIAL' ? 'SEQUENTIAL' : 'PARALLEL',
			totalSigners: count($signers),
			document: $document,
			metadata: $metadata,
			locale: 'pt-BR',
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
		// Front-end submits cpf XOR cnpj after stripping non-digits; pass
		// whichever the SDK Signer field expects without inferring on the
		// back-end so an 11-digit value is never silently sent as a CNPJ.
		return new Signer(
			name: $signerData['name'] ?? '',
			userExternalId: $externalId,
			email: $signerData['email'] ?? null,
			phone: $signerData['phone'] ?? null,
			cpf: isset($signerData['cpf']) && $signerData['cpf'] !== '' ? $signerData['cpf'] : null,
			cnpj: isset($signerData['cnpj']) && $signerData['cnpj'] !== '' ? $signerData['cnpj'] : null,
		);
	}

	/**
	 * Reject combinations the SignDocs API would also reject, before making
	 * the round-trip. Today the only such combination is digital-certificate
	 * signing with multiple signers in parallel order — ICP-Brasil signatures
	 * chain across signers and the verifier needs them in deterministic order.
	 *
	 * Throws InvalidArgumentException with a stable English message so the
	 * controller can map to a 422 with a code the front-end can localize.
	 */
	private function validateOptions(string $mode, string $orderUpper, int $signerCount): void {
		if ($this->requiresSequentialOrder($mode) && $signerCount >= 2 && $orderUpper !== 'SEQUENTIAL') {
			throw new \InvalidArgumentException(
				'Digital certificate signing requires sequential order with multiple signers.'
			);
		}
	}

	/**
	 * Validate every signer carries exactly one of cpf / cnpj and that
	 * the value passes Brazilian check-digit arithmetic. Saves a round-trip
	 * to SignDocs (which would also reject an invalid fiscal id) and
	 * surfaces the fault on the *specific* signer that's wrong, so the
	 * front-end can highlight it.
	 *
	 * @param array<int, array<string, mixed>> $signers
	 */
	private function validateSigners(array $signers): void {
		foreach (array_values($signers) as $i => $sig) {
			$cpf = isset($sig['cpf']) ? (string)$sig['cpf'] : '';
			$cnpj = isset($sig['cnpj']) ? (string)$sig['cnpj'] : '';

			if ($cpf === '' && $cnpj === '') {
				throw new \InvalidArgumentException(
					'Signer #' . ($i + 1) . ' is missing a CPF or CNPJ.'
				);
			}
			if ($cpf !== '' && !CpfCnpjValidator::isValidCpf($cpf)) {
				throw new \InvalidArgumentException(
					'Signer #' . ($i + 1) . ' has an invalid CPF.'
				);
			}
			if ($cnpj !== '' && !CpfCnpjValidator::isValidCnpj($cnpj)) {
				throw new \InvalidArgumentException(
					'Signer #' . ($i + 1) . ' has an invalid CNPJ.'
				);
			}
		}
	}

	private function requiresSequentialOrder(string $mode): bool {
		// digital_certificate is the canonical UI input; icp_a1 / icp_a3 are
		// retained as legacy aliases (see mapModeToProfile).
		return in_array($mode, ['digital_certificate', 'icp_a1', 'icp_a3'], true);
	}

	private function mapModeToProfile(string $mode): string {
		return match ($mode) {
			// 'digital_certificate' covers both A1 and A3 — the actual
			// cert class is decided at signing-session time by the signer's
			// device (browser cert store for A1, smart-card token for A3).
			// The legacy 'icp_a1' / 'icp_a3' inputs are kept as aliases so
			// older NC client builds during a rolling rollout don't break.
			'digital_certificate', 'icp_a1', 'icp_a3' => 'DIGITAL_CERTIFICATE',
			'biometric' => 'BIOMETRIC',
			'click_plus_otp' => 'CLICK_PLUS_OTP',
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
			$this->tagObjectMapper->assignTags((string)$fileId, 'files', $tag->getId());
		} catch (TagNotFoundException $e) {
			$this->logger->warning('Status tag missing, skipping', ['tag' => $tagName, 'exception' => $e]);
		}
	}
}
