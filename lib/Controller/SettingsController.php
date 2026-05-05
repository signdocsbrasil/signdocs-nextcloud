<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Controller;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Service\CredentialsService;
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
