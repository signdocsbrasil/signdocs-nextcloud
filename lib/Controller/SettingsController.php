<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Controller;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly CredentialsService $credentials,
		private readonly IUserSession $userSession,
		private readonly SignDocsClientFactory $clientFactory,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Per-user OAuth device-flow lands in v1.1 — the PHP SDK currently only
	 * supports client_credentials and private_key_jwt grants, not the
	 * device_code grant required for desktop/NC-app onboarding. Until that
	 * lands, return 501 so the front-end can render the "ask your admin to
	 * configure SignDocs" message.
	 *
	 * @NoAdminRequired
	 */
	public function startDeviceFlow(): DataResponse {
		return new DataResponse(
			['error' => 'not_implemented', 'message' => 'Per-user OAuth chega na v1.1; por ora seu administrador configura as credenciais do tenant.'],
			Http::STATUS_NOT_IMPLEMENTED
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function pollDeviceFlow(string $deviceCode): DataResponse {
		return new DataResponse(['error' => 'not_implemented'], Http::STATUS_NOT_IMPLEMENTED);
	}

	/**
	 * @NoAdminRequired
	 */
	public function disconnect(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		$this->credentials->deleteUserOAuthToken($user->getUID());
		return new DataResponse(['ok' => true]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function setSignedFolder(string $path): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		$this->credentials->setDefaultSignedFolder($user->getUID(), $path);
		return new DataResponse(['ok' => true]);
	}

	/**
	 * Admin-only: validate that the configured (or just-typed) credentials
	 * can actually authenticate against the SignDocs API.
	 *
	 * Strategy: build a transient SDK client and call a lightweight
	 * authenticated read — `signingSessions->list(limit:1)`. That exercises
	 * the full client_credentials grant flow against the configured base
	 * URL and proves the creds are real, the network path works, and the
	 * tenant has at least the basic "list sessions" scope.
	 *
	 * If clientId+clientSecret are passed in the body the test runs against
	 * those (the admin can validate before saving). If they're omitted the
	 * test runs against whatever's already stored on disk.
	 */
	public function testConnection(?string $clientId = null, ?string $clientSecret = null): DataResponse {
		try {
			if ($clientId !== null && $clientId !== '' && $clientSecret !== null && $clientSecret !== '') {
				$client = $this->clientFactory->forCredentials($clientId, $clientSecret);
			} else {
				$creds = $this->credentials->getTenantApiKey();
				if ($creds === null) {
					return new DataResponse([
						'ok' => false,
						'error' => 'no_credentials',
						'message' => 'Nenhuma credencial configurada. Cole o Client ID e Client Secret antes de testar.',
					], Http::STATUS_UNPROCESSABLE_ENTITY);
				}
				$client = $this->clientFactory->forCredentials($creds['clientId'], $creds['clientSecret']);
			}

			// Authenticated, side-effect-free read to confirm the credentials.
			// The list endpoint requires a status filter (ACTIVE/COMPLETED/…),
			// so pass one; ACTIVE + limit:1 keeps the payload trivially small.
			$client->signingSessions->list(
				new \SignDocsBrasil\Api\Models\SigningSessionListParams(status: 'ACTIVE', limit: 1)
			);

			return new DataResponse([
				'ok' => true,
				'baseUrl' => $this->credentials->getApiBaseUrl(),
			]);
		} catch (\Throwable $e) {
			return new DataResponse([
				'ok' => false,
				'error' => 'auth_failed',
				'message' => $e->getMessage(),
				'baseUrl' => $this->credentials->getApiBaseUrl(),
			], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * Admin-only: configure tenant-wide shared API key + webhook secret + tenant mode.
	 */
	public function adminUpdate(string $tenantMode, ?string $clientId = null, ?string $clientSecret = null, ?string $webhookSecret = null): DataResponse {
		$this->credentials->setTenantMode($tenantMode);
		if ($tenantMode === Application::SETTING_TENANT_MODE_SHARED && $clientId !== null && $clientSecret !== null) {
			$this->credentials->storeTenantApiKey($clientId, $clientSecret);
		}
		if ($webhookSecret !== null && $webhookSecret !== '') {
			$this->credentials->setWebhookSecret($webhookSecret);
		}
		return new DataResponse(['ok' => true]);
	}
}
