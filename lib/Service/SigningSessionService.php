<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Service;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Db\SigningSession;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;
use SignDocsBrasil\Api\Models\AddEnvelopeSessionRequest;
use SignDocsBrasil\Api\Models\CreateEnvelopeRequest;
use SignDocsBrasil\Api\Models\CreateSigningSessionRequest;
use SignDocsBrasil\Api\Models\Owner;
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
	/** Leading bytes of a PDF. */
	private const MAGIC_PDF = '%PDF-';

	/**
	 * Leading byte of a DER-encoded PKCS#7/CMS structure (ASN.1 SEQUENCE). Weak
	 * on its own, but enough to tell a real .p7s from an S3 error page.
	 */
	private const MAGIC_CMS = "\x30";

	/**
	 * How many fetch attempts a non-PDF session gets before we accept that no
	 * artifact exists. Only applies to rows whose policy wasn't recorded (created
	 * before `mode` was persisted) — everything else is decided from the policy.
	 * At the job's 5-minute cadence this is roughly an hour.
	 */
	private const MAX_ARTIFACT_ATTEMPTS = 12;

	/** The mutually exclusive status badges a file can carry. */
	private const STATUS_TAGS = [
		Application::TAG_PENDENTE,
		Application::TAG_ASSINADO,
		Application::TAG_CANCELADO,
	];

	public function __construct(
		private readonly SignDocsClientFactory $clientFactory,
		private readonly SigningSessionMapper $mapper,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly ISystemTagManager $tagManager,
		private readonly ISystemTagObjectMapper $tagObjectMapper,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly CredentialsService $credentials,
		private readonly IClientService $clientService,
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

		// Identify the NC user as the request owner so SignDocs auto-dispatches
		// the invite email to each signer (when their email differs from the
		// owner's) and sends completion notifications. Requires the NC user to
		// have an email set; without an owner the API sends nothing.
		$ownerEmail = $user->getEMailAddress();
		$owner = ($ownerEmail !== null && $ownerEmail !== '')
			? new Owner(email: $ownerEmail, name: $user->getDisplayName())
			: null;

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		if (empty($nodes) || !$nodes[0] instanceof File) {
			throw new NotFoundException('File not found in user storage: ' . $fileId);
		}
		$file = $nodes[0];
		$content = $file->getContent();
		$this->validateDocumentFormat($content, (string)($options['mode'] ?? 'electronic'));
		$documentInline = [
			'content' => base64_encode($content),
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

		$mode = (string)($options['mode'] ?? 'electronic');

		if (count($signers) <= 1) {
			return $this->createSingle($client, $policy, $signers[0] ?? null, $documentInline, $metadata, $fileId, $userId, $owner, $mode);
		}

		return $this->createEnvelope($client, $policy, $signers, $documentInline, $options['order'] ?? 'PARALLEL', $metadata, $fileId, $userId, $owner, $mode);
	}

	private function createSingle($client, Policy $policy, ?array $signerData, array $document, array $metadata, int $fileId, string $userId, ?Owner $owner = null, string $mode = 'electronic'): SigningSession {
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
			owner: $owner,
		);
		$apiSession = $client->signingSessions->create($request);

		return $this->persist(
			sessionId: $apiSession->sessionId,
			fileId: $fileId,
			userId: $userId,
			metadata: [
				'kind' => 'session',
				// Recorded so saveSignedDocument knows, without another API call,
				// whether a non-PDF can ever yield a signed artifact.
				'mode' => $mode,
				'transactionId' => $apiSession->transactionId,
				'signers' => [['email' => $signerData['email'] ?? null, 'name' => $signerData['name'] ?? null]],
			],
			transactionId: $apiSession->transactionId,
		);
	}

	private function createEnvelope($client, Policy $policy, array $signers, array $document, string $order, array $metadata, int $fileId, string $userId, ?Owner $owner = null, string $mode = 'electronic'): SigningSession {
		$envelope = $client->envelopes->create(new CreateEnvelopeRequest(
			signingMode: strtoupper($order) === 'SEQUENTIAL' ? 'SEQUENTIAL' : 'PARALLEL',
			totalSigners: count($signers),
			document: $document,
			metadata: $metadata,
			locale: 'pt-BR',
			owner: $owner,
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
				'mode' => $mode,
				'shareLinks' => $shareLinks,
			],
		);
	}

	/**
	 * Cancel a signing flow so nobody can sign it any more.
	 *
	 * Envelopes go through the envelope's own cancel endpoint, which is what the
	 * Telegram bot uses. It transitions every non-terminal member session and its
	 * transaction in one auditable operation and — unlike cancelling the members
	 * individually — actually moves the envelope's own status to CANCELLED.
	 * Signatures already collected are preserved upstream and reported back, so
	 * cancelling never invalidates evidence that was already gathered.
	 *
	 * Single-signer rows cancel their one session.
	 *
	 * Both endpoints are idempotent server-side, so re-cancelling is a safe no-op
	 * rather than an error.
	 *
	 * @return array{cancelled: int, preservedSigned: int, alreadyCancelled: bool}
	 * @throws DoesNotExistException when the id is not mirrored locally
	 * @throws NotConnectedException when the user has no linked SignDocs account
	 */
	public function cancelSigningFlow(string $sessionId, ?string $reason = null): array {
		$entity = $this->mapper->findBySessionId($sessionId);
		$userId = $entity->getUserId();

		$meta = json_decode((string)$entity->getMetadata(), true);
		$meta = is_array($meta) ? $meta : [];

		if (($meta['kind'] ?? 'session') === 'envelope') {
			$response = $this->clientFactory
				->envelopesFor($userId)
				->cancel($entity->getSessionId(), $reason ?? 'cancelled_via_nextcloud');
			$result = [
				'cancelled' => $response->cancelledCount,
				'preservedSigned' => $response->preservedSignedCount,
				'alreadyCancelled' => $response->alreadyCancelled,
			];
		} else {
			$this->clientFactory->signingSessionsFor($userId)->cancel($entity->getSessionId());
			$result = ['cancelled' => 1, 'preservedSigned' => 0, 'alreadyCancelled' => false];
		}

		$this->applyToEntity($entity, 'cancelled', null);

		$this->logger->info('Cancelled SignDocs signing flow', [
			'sessionId' => $sessionId,
			'cancelled' => $result['cancelled'],
			'preservedSigned' => $result['preservedSigned'],
		]);

		return $result;
	}

	/**
	 * Apply a status update keyed by session/envelope id. Used by the polling
	 * background job, which reconciles by the id it stored at create time.
	 */
	public function applyStatusUpdate(string $sessionId, string $status, ?string $signedFileId = null): void {
		try {
			$entity = $this->mapper->findBySessionId($sessionId);
		} catch (DoesNotExistException) {
			$this->logger->warning('Status update for unknown SignDocs session', ['sessionId' => $sessionId]);
			return;
		}
		$this->applyToEntity($entity, $status, $signedFileId);
	}

	/**
	 * Apply a webhook-delivered status update. TRANSACTION.* events are keyed by
	 * transactionId; ENVELOPE.* events carry the envelope id (which single- and
	 * multi-signer flows persist as session_id). Resolve by transactionId first,
	 * then fall back to the session/envelope id.
	 */
	public function applyStatusUpdateFromWebhook(
		?string $transactionId,
		?string $sessionId,
		string $status,
		?string $signedFileId = null,
	): void {
		$entity = null;
		if ($transactionId !== null && $transactionId !== '') {
			try {
				$entity = $this->mapper->findByTransactionId($transactionId);
			} catch (DoesNotExistException) {
				// fall through to session-id resolution
			}
		}
		if ($entity === null && $sessionId !== null && $sessionId !== '') {
			try {
				$entity = $this->mapper->findBySessionId($sessionId);
			} catch (DoesNotExistException) {
				// unknown below
			}
		}
		if ($entity === null) {
			$this->logger->warning('Webhook for unknown SignDocs session', [
				'transactionId' => $transactionId,
				'sessionId' => $sessionId,
			]);
			return;
		}
		$this->applyToEntity($entity, $status, $signedFileId);
	}

	/**
	 * Normalise the incoming status to a canonical lowercase value, persist it,
	 * and badge the file. Shared by the polling and webhook paths.
	 */
	private function applyToEntity(SigningSession $entity, string $status, ?string $signedFileId): void {
		$canonical = self::canonicalStatus($status);

		$entity->setStatus($canonical);
		$entity->setUpdatedAt($this->time->getTime());
		if ($signedFileId !== null) {
			$entity->setSignedFileId($signedFileId);
		}
		$this->mapper->update($entity);

		$tag = match ($canonical) {
			'completed' => Application::TAG_ASSINADO,
			'cancelled', 'expired', 'failed' => Application::TAG_CANCELADO,
			default => Application::TAG_PENDENTE,
		};
		$this->applyStatusTag($entity->getFileId(), $tag);
	}

	/**
	 * Retrieve the signed/combined PDF for a completed session and store it in
	 * the user's signed folder (default /Assinados), recording the resulting NC
	 * node id. Idempotent: a session that already has a signed_file_id is left
	 * untouched. Driven by the FetchSignedDocuments background job.
	 */
	public function saveSignedDocument(SigningSession $entity): void {
		if ($entity->getSignedFileId() !== null) {
			return;
		}

		$userId = $entity->getUserId();
		$meta = json_decode((string)$entity->getMetadata(), true);
		$meta = is_array($meta) ? $meta : [];
		$kind = $meta['kind'] ?? 'session';

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$originalName = $this->originalFileName($userFolder, $entity->getFileId());

		// Envelopes (session_id == envelopeId) deliver a combined stamp, which is
		// always a PDF summary regardless of the source document's format.
		if ($kind === 'envelope') {
			$client = $this->clientFactory->forUser($userId);
			$url = $client->envelopes->combinedStamp($entity->getSessionId())->downloadUrl;
			if (!is_string($url) || $url === '') {
				$this->logger->info('Combined stamp not available yet', ['sessionId' => $entity->getSessionId()]);
				return;
			}
			$content = $this->fetchArtifact($url, self::MAGIC_PDF);
			if ($content === null) {
				return;
			}
			$this->storeArtifacts($entity, $userFolder, $userId, [
				self::signedFileName($originalName) => $content,
			]);
			return;
		}

		// Established on an earlier run that this row can never produce an
		// artifact — don't spend an API call on it every 5 minutes.
		if (!empty($meta['noSignedArtifact'])) {
			return;
		}

		$transactionId = $entity->getTransactionId();
		if ($transactionId === null || $transactionId === '') {
			$this->logger->warning('No transactionId to fetch signed document', ['sessionId' => $entity->getSessionId()]);
			return;
		}

		$urls = $this->downloadUrls($userId, $transactionId);
		$signedUrl = isset($urls['signedUrl']) ? (string)$urls['signedUrl'] : '';
		$signatureUrl = isset($urls['signatureUrl']) ? (string)$urls['signatureUrl'] : '';

		// PDF flow: one signed/stamped PDF.
		if ($signedUrl !== '') {
			$content = $this->fetchArtifact($signedUrl, self::MAGIC_PDF);
			if ($content === null) {
				return;
			}
			$this->storeArtifacts($entity, $userFolder, $userId, [
				self::signedFileName($originalName) => $content,
			]);
			return;
		}

		$mode = isset($meta['mode']) ? (string)$meta['mode'] : null;
		$format = isset($urls['documentFormat']) ? (string)$urls['documentFormat'] : '';

		// A non-PDF upload (documentFormat=generic) only ever gets an artifact on
		// the certificate path, where the signing step writes a detached .p7s.
		// Under a click/OTP policy nothing is produced at all, so give up now.
		//
		// This has to be decided from the policy, NOT from the presence of
		// signatureUrl: the API presigns that key without checking whether the
		// object exists, so a click-signed .docx still comes back with a
		// signatureUrl that 404s on GET.
		if ($format === 'generic' && $mode !== null && !self::isDigitalCertificateMode($mode)) {
			$this->markNoSignedArtifact($entity, $meta, 'signed without a digital certificate', $mode);
			return;
		}

		// CAdES flow: the signature is detached, so the document alone proves
		// nothing and the .p7s alone can't be read. Save the pair — the exact
		// bytes that were signed (from the API, not the local file, which may
		// have been edited since) plus the signature beside it. Same two-file
		// result the Google Drive add-on produces for native-format signing.
		if ($signatureUrl !== '' && $this->storeDetachedPair($entity, $userFolder, $userId, $originalName, $urls)) {
			return;
		}

		// Nothing landed. Rows created before the policy was recorded can't be
		// classified, so bound the retries instead of warning forever — a real
		// .p7s exists the moment the transaction completes, so if it hasn't
		// appeared within the cap it never will.
		if ($format === 'generic' && $mode === null) {
			$attempts = (int)($meta['artifactAttempts'] ?? 0) + 1;
			if ($attempts >= self::MAX_ARTIFACT_ATTEMPTS) {
				$this->markNoSignedArtifact($entity, $meta, 'no artifact after ' . $attempts . ' attempts', $mode);
				return;
			}
			$meta['artifactAttempts'] = $attempts;
			$entity->setMetadata(json_encode($meta, JSON_THROW_ON_ERROR));
			$entity->setUpdatedAt($this->time->getTime());
			$this->mapper->update($entity);
		}

		// Artifact not ready yet — the job retries on its next run.
		$this->logger->info('Signed artifact not available yet', ['sessionId' => $entity->getSessionId()]);
	}

	/**
	 * Flag a completed session as having no signed artifact so the fetch job
	 * stops spending an API call on it every run. Deliberately does not touch
	 * signed_file_id: there is no file, and the status badge stays "assinado"
	 * because the signature itself is valid and evidenced — only the signed
	 * *document* is missing.
	 *
	 * @param array<string, mixed> $meta
	 */
	private function markNoSignedArtifact(SigningSession $entity, array $meta, string $reason, ?string $mode): void {
		$this->logger->info('No signed artifact to save back for this session', [
			'sessionId' => $entity->getSessionId(),
			'reason' => $reason,
			'mode' => $mode,
		]);
		$meta['noSignedArtifact'] = true;
		$entity->setMetadata(json_encode($meta, JSON_THROW_ON_ERROR));
		$entity->setUpdatedAt($this->time->getTime());
		$this->mapper->update($entity);
	}

	/**
	 * Save the signed document together with its detached .p7s signature.
	 *
	 * @param array<string, mixed> $urls download URLs as returned by the API
	 * @return bool whether anything was saved; false means try again later
	 */
	private function storeDetachedPair(
		SigningSession $entity,
		Folder $userFolder,
		string $userId,
		?string $originalName,
		array $urls,
	): bool {
		$signature = $this->fetchArtifact((string)$urls['signatureUrl'], self::MAGIC_CMS);
		if ($signature === null) {
			return false;
		}

		$files = [];
		$originalUrl = isset($urls['originalUrl']) ? (string)$urls['originalUrl'] : '';
		if ($originalUrl !== '') {
			$document = $this->fetchArtifact($originalUrl, null);
			if ($document !== null) {
				$files[self::signedFileName($originalName, self::fileExtension($originalName) ?: 'bin')] = $document;
			}
		}
		if (empty($files)) {
			// No original to pair with — still worth keeping the signature, which
			// verifies against the document already in the user's storage.
			$this->logger->warning('Saving detached signature without its document copy', [
				'sessionId' => $entity->getSessionId(),
			]);
		}
		$files[self::signedFileName($originalName, 'p7s')] = $signature;

		$this->storeArtifacts($entity, $userFolder, $userId, $files);
		return true;
	}

	/**
	 * Write artifacts into the user's signed folder and point the mirror row at
	 * the first one (the document a user actually opens). Any extras — today just
	 * the .p7s — are recorded in metadata under `extraFileIds`.
	 *
	 * Names are collision-resolved off the *first* entry so a pair keeps a
	 * matching basename: `contrato-assinado (2).docx` + `contrato-assinado (2).p7s`.
	 *
	 * @param array<string, string> $files filename => content, primary first
	 */
	private function storeArtifacts(
		SigningSession $entity,
		Folder $userFolder,
		string $userId,
		array $files,
	): void {
		if (empty($files)) {
			return;
		}
		$folder = $this->resolveSignedFolder($userFolder, $this->credentials->getDefaultSignedFolder($userId));

		$names = array_keys($files);
		$primaryName = $folder->getNonExistingName($names[0]);
		$sharedBase = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $primaryName) ?? $primaryName;

		$primary = null;
		$extraIds = [];
		foreach ($names as $i => $name) {
			$target = $i === 0
				? $primaryName
				: $folder->getNonExistingName($sharedBase . '.' . self::fileExtension($name));
			$node = $folder->newFile($target, $files[$name]);
			if ($i === 0) {
				$primary = $node;
			} else {
				$extraIds[] = (string)$node->getId();
			}
			$this->logger->info('Saved signed artifact to Nextcloud', [
				'sessionId' => $entity->getSessionId(),
				'path' => $node->getPath(),
			]);
		}

		if ($primary === null) {
			return;
		}
		$entity->setSignedFileId((string)$primary->getId());
		if (!empty($extraIds)) {
			$meta = json_decode((string)$entity->getMetadata(), true);
			$meta = is_array($meta) ? $meta : [];
			$meta['extraFileIds'] = $extraIds;
			$entity->setMetadata(json_encode($meta, JSON_THROW_ON_ERROR));
		}
		$entity->setUpdatedAt($this->time->getTime());
		$this->mapper->update($entity);
	}

	/**
	 * Every download URL the API has for a transaction, plus the format it
	 * decided the document is. `signatureUrl` and `documentFormat` need SDK
	 * >= 1.8.0; earlier releases parsed neither.
	 *
	 * @return array<string, mixed>
	 */
	private function downloadUrls(string $userId, string $transactionId): array {
		$response = $this->clientFactory->documentsFor($userId)->download($transactionId);
		return [
			'originalUrl' => $response->originalUrl,
			'signedUrl' => $response->signedUrl,
			'signatureUrl' => $response->signatureUrl,
			'documentFormat' => $response->documentFormat,
		];
	}

	/**
	 * Fetch an artifact from a SignDocs-issued presigned URL. Returns null (and
	 * logs) on any failure so the job can retry later. Validates HTTPS; the URL
	 * is trusted (issued by the SignDocs API), so no Content-Type allowlist is
	 * applied.
	 *
	 * @param string|null $magic leading bytes to require, as a sanity check that
	 *                           we got the artifact and not an error page. Pass
	 *                           null for formats with no fixed prefix (the
	 *                           original document can be any office format).
	 */
	private function fetchArtifact(string $url, ?string $magic): ?string {
		if (!str_starts_with(strtolower($url), 'https://')) {
			$this->logger->warning('Refusing non-HTTPS artifact URL');
			return null;
		}
		try {
			$response = $this->clientService->newClient()->get($url, [
				'connect_timeout' => 5,
				'timeout' => 60,
				'verify' => true,
				'http_errors' => true,
			]);
			$body = (string)$response->getBody();
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to fetch signed artifact', ['exception' => $e]);
			return null;
		}
		if ($body === '') {
			$this->logger->warning('Signed artifact was empty');
			return null;
		}
		if ($magic !== null && strncmp($body, $magic, strlen($magic)) !== 0) {
			$this->logger->warning('Signed artifact did not start with the expected bytes', [
				'expected' => bin2hex($magic),
				'got' => bin2hex(substr($body, 0, strlen($magic))),
			]);
			return null;
		}
		return $body;
	}

	/**
	 * Resolve (creating if needed) the destination folder for signed documents.
	 * Falls back to the user's root when the configured path is empty.
	 */
	private function resolveSignedFolder(Folder $userFolder, string $path): Folder {
		$path = '/' . trim($path, '/');
		if ($path === '/') {
			return $userFolder;
		}
		if ($userFolder->nodeExists($path)) {
			$existing = $userFolder->get($path);
			if ($existing instanceof Folder) {
				return $existing;
			}
		}
		return $userFolder->newFolder($path);
	}

	private function originalFileName(Folder $userFolder, int $fileId): ?string {
		$nodes = $userFolder->getById($fileId);
		$node = $nodes[0] ?? null;
		return $node !== null ? $node->getName() : null;
	}

	/**
	 * Normalise any upstream status (single-signer session statuses, envelope
	 * statuses like CREATED/ACTIVE/COMPLETED/CANCELLED/EXPIRED, webhook-derived
	 * statuses) to the mirror's canonical enum. Every non-terminal value maps to
	 * 'pending' so the row stays in the polling set until it resolves.
	 */
	public static function canonicalStatus(string $status): string {
		return match (strtolower($status)) {
			'completed', 'signed', 'finalized', 'all_signed' => 'completed',
			'cancelled', 'canceled', 'rejected' => 'cancelled',
			'expired' => 'expired',
			'failed' => 'failed',
			default => 'pending',
		};
	}

	/**
	 * Derive the signed-document filename from the original: strip a trailing
	 * extension and append "-assinado.pdf".
	 */
	/**
	 * Name for a saved artifact: the original basename plus `-assinado` and the
	 * given extension. Defaults to `pdf` because that's what every PDF and
	 * combined-stamp flow produces; the CAdES flow passes the source document's
	 * own extension for the document copy and `p7s` for the detached signature.
	 */
	public static function signedFileName(?string $originalName, string $extension = 'pdf'): string {
		$base = ($originalName !== null && $originalName !== '') ? $originalName : 'documento';
		$base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $base) ?? $base;
		return $base . '-assinado.' . ltrim($extension, '.');
	}

	/** Lowercase extension without the dot, or '' when there isn't one. */
	public static function fileExtension(?string $name): string {
		if ($name === null || $name === '') {
			return '';
		}
		return preg_match('/\.([A-Za-z0-9]{1,5})$/', $name, $m) === 1 ? strtolower($m[1]) : '';
	}

	/**
	 * True for the UI's canonical certificate mode and its legacy aliases. These
	 * are the only modes that produce a detached CAdES signature for a non-PDF.
	 */
	public static function isDigitalCertificateMode(string $mode): bool {
		return in_array($mode, ['digital_certificate', 'icp_a1', 'icp_a3'], true);
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
	 * Reject order/mode combinations the dialog cannot produce, before making
	 * the round-trip. Sequential order and ICP-Brasil digital certificates are
	 * bound to each other in both directions: ICP-Brasil signatures chain
	 * across signers so the verifier needs them in deterministic order, and
	 * conversely sequential order is only offered for that mode — a click or
	 * OTP envelope has nothing to chain, so the dropdown no longer exposes it.
	 *
	 * Throws InvalidArgumentException with a stable English message so the
	 * controller can map to a 422 with a code the front-end can localize.
	 */
	private function validateOptions(string $mode, string $orderUpper, int $signerCount): void {
		$requiresSequential = $this->requiresSequentialOrder($mode);

		if ($requiresSequential && $signerCount >= 2 && $orderUpper !== 'SEQUENTIAL') {
			throw new \InvalidArgumentException(
				'Digital certificate signing requires sequential order with multiple signers.'
			);
		}

		// A stale front-end (or a hand-rolled request) asking for sequential
		// order under a click/OTP policy. With a single signer the order is
		// inert — createSingle never builds an envelope — so only guard the
		// multi-signer case, matching the rule above.
		if (!$requiresSequential && $signerCount >= 2 && $orderUpper === 'SEQUENTIAL') {
			throw new \InvalidArgumentException(
				'Sequential order requires digital certificate signing.'
			);
		}
	}

	/**
	 * A non-PDF may only be signed with an ICP-Brasil certificate.
	 *
	 * The API keeps non-PDF uploads as documentFormat=generic, and the only
	 * artifact that path can produce is the detached .p7s written by the
	 * certificate step. Under a click/OTP policy the signature is recorded and
	 * evidenced but no signed document exists to hand back, which reads to the
	 * user as a silent failure. So refuse the combination outright rather than
	 * accept a request whose result can't be delivered.
	 *
	 * Decided by sniffing the leading bytes, exactly as the API does — the
	 * filename is not authoritative, and gating on the extension would reject a
	 * mislabelled PDF the API would happily have stamped.
	 *
	 * Lifts once we can convert to PDF before upload (Collabora), which makes
	 * click/OTP viable again by signing a PDF rendition.
	 */
	private function validateDocumentFormat(string $content, string $mode): void {
		if (strncmp($content, self::MAGIC_PDF, strlen(self::MAGIC_PDF)) === 0) {
			return;
		}
		if (self::isDigitalCertificateMode($mode)) {
			return;
		}
		throw new \InvalidArgumentException(
			'Non-PDF documents require digital certificate signing.'
		);
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
		// Certificate signing chains each signature onto the previous one, so it
		// is exactly the certificate modes that force sequential order.
		return self::isDigitalCertificateMode($mode);
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

	private function persist(string $sessionId, int $fileId, string $userId, array $metadata, ?string $transactionId = null): SigningSession {
		$now = $this->time->getTime();
		$entity = new SigningSession();
		$entity->setSessionId($sessionId);
		$entity->setTransactionId($transactionId);
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

	/**
	 * Badge the file with its current signing status.
	 *
	 * The three signdocs:* tags are mutually exclusive — a document is pending
	 * OR signed OR cancelled — so the previous badge has to come off. assignTags
	 * only ever adds, which left every completed document still showing
	 * "Pendente" in the Files list alongside "Assinado".
	 */
	private function applyStatusTag(int $fileId, string $tagName): void {
		try {
			$tags = $this->tagManager->getAllTags(null, $tagName);
			$tag = $tags[array_key_first($tags)] ?? $this->tagManager->createTag($tagName, true, false);
			$this->tagObjectMapper->assignTags((string)$fileId, 'files', $tag->getId());
		} catch (TagNotFoundException $e) {
			$this->logger->warning('Status tag missing, skipping', ['tag' => $tagName, 'exception' => $e]);
			return;
		}

		$this->clearOtherStatusTags($fileId, $tagName);
	}

	/**
	 * Strip whichever of the other two status tags the file still carries.
	 * unassignTags is documented to fail silently when the relationship was
	 * never there, so this runs unconditionally rather than reading the
	 * current assignments first.
	 */
	private function clearOtherStatusTags(int $fileId, string $keep): void {
		foreach (self::STATUS_TAGS as $name) {
			if ($name === $keep) {
				continue;
			}
			try {
				$tags = $this->tagManager->getAllTags(null, $name);
				$tag = $tags[array_key_first($tags)] ?? null;
				if ($tag !== null) {
					$this->tagObjectMapper->unassignTags((string)$fileId, 'files', $tag->getId());
				}
			} catch (TagNotFoundException) {
				// Tag was never created on this instance — nothing to strip.
			}
		}
	}
}
