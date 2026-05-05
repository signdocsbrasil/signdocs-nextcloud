<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Service;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICredentialsManager;

/**
 * Stores SignDocs Brasil credentials.
 *
 * Per-user OAuth tokens go through ICredentialsManager (encrypted at rest).
 * Tenant-wide shared API keys go through the same manager keyed by app, not user.
 * Non-secret config (tenant mode, default folder, base URL) goes through IConfig.
 */
class CredentialsService {
	private const CRED_USER_OAUTH = 'oauth_token';
	private const CRED_TENANT_API_KEY = 'tenant_api_key';

	public function __construct(
		private readonly ICredentialsManager $credentialsManager,
		private readonly IConfig $config,
	) {
	}

	public function storeUserOAuthToken(string $userId, array $token): void {
		$this->credentialsManager->store($userId, self::credKey(self::CRED_USER_OAUTH), $token);
	}

	public function getUserOAuthToken(string $userId): ?array {
		$value = $this->credentialsManager->retrieve($userId, self::credKey(self::CRED_USER_OAUTH));
		return is_array($value) ? $value : null;
	}

	public function deleteUserOAuthToken(string $userId): void {
		$this->credentialsManager->delete($userId, self::credKey(self::CRED_USER_OAUTH));
	}

	public function storeTenantApiKey(string $clientId, string $clientSecret): void {
		$this->credentialsManager->store('', self::credKey(self::CRED_TENANT_API_KEY), [
			'clientId' => $clientId,
			'clientSecret' => $clientSecret,
		]);
	}

	public function getTenantApiKey(): ?array {
		$value = $this->credentialsManager->retrieve('', self::credKey(self::CRED_TENANT_API_KEY));
		return is_array($value) ? $value : null;
	}

	public function deleteTenantApiKey(): void {
		$this->credentialsManager->delete('', self::credKey(self::CRED_TENANT_API_KEY));
	}

	public function getTenantMode(): string {
		// v1 default is SHARED (admin-managed credentials) — OAuth-per-user
		// requires device-flow support in the PHP SDK, which lands in v1.1.
		return $this->config->getAppValue(
			Application::APP_ID,
			'tenant_mode',
			Application::SETTING_TENANT_MODE_SHARED
		);
	}

	public function setTenantMode(string $mode): void {
		if (!in_array($mode, [Application::SETTING_TENANT_MODE_OAUTH, Application::SETTING_TENANT_MODE_SHARED], true)) {
			throw new \InvalidArgumentException('Unknown tenant mode: ' . $mode);
		}
		$this->config->setAppValue(Application::APP_ID, 'tenant_mode', $mode);
	}

	public function getApiBaseUrl(): string {
		return $this->config->getAppValue(
			Application::APP_ID,
			'api_base_url',
			'https://api.signdocs.com.br'
		);
	}

	public function getWebhookSecret(): ?string {
		$secret = $this->config->getAppValue(Application::APP_ID, 'webhook_secret', '');
		return $secret !== '' ? $secret : null;
	}

	public function setWebhookSecret(string $secret): void {
		$this->config->setAppValue(Application::APP_ID, 'webhook_secret', $secret);
	}

	public function getDefaultSignedFolder(string $userId): string {
		return $this->config->getUserValue($userId, Application::APP_ID, 'signed_folder', '/Assinados');
	}

	public function setDefaultSignedFolder(string $userId, string $path): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'signed_folder', $path);
	}

	private static function credKey(string $key): string {
		return Application::APP_ID . '::' . $key;
	}
}
