<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Service;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCP\IUserSession;
use SignDocsBrasil\Api\Config;
use SignDocsBrasil\Api\Resources\DocumentsResource;
use SignDocsBrasil\Api\Resources\EnvelopesResource;
use SignDocsBrasil\Api\Resources\SigningSessionsResource;
use SignDocsBrasil\Api\SignDocsBrasilClient;

/**
 * Builds a SignDocsBrasilClient for the calling user.
 *
 * Tenant mode resolution:
 * - 'oauth':  use the current user's OAuth token (per-user attribution).
 * - 'shared': use the tenant-wide clientId/clientSecret from app config.
 *
 * The shared mode is admin-controlled and useful for orgs that want one billing
 * relationship across all NC users; OAuth mode is the default for per-user
 * audit attribution and is what NC App Store reviewers expect.
 */
class SignDocsClientFactory {
	public function __construct(
		private readonly CredentialsService $credentials,
		private readonly IUserSession $userSession,
	) {
	}

	public function forCurrentUser(): SignDocsBrasilClient {
		$mode = $this->credentials->getTenantMode();
		if ($mode === Application::SETTING_TENANT_MODE_SHARED) {
			return new SignDocsBrasilClient($this->sharedConfig());
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No active user session.');
		}
		return new SignDocsBrasilClient($this->oauthConfig($user->getUID()));
	}

	/**
	 * Build a client for a specific user — used by background jobs that have no
	 * session context. Falls back to the shared tenant client if tenant mode is
	 * shared (the userId is irrelevant in that case).
	 */
	public function forUser(string $userId): SignDocsBrasilClient {
		return new SignDocsBrasilClient($this->configForUser($userId));
	}

	/**
	 * The documents resource for a specific user. A separate accessor because
	 * DocumentsResource is final and so can't be mocked — tests substitute this
	 * method with a real resource wrapping a mocked HttpClient.
	 */
	public function documentsFor(string $userId): DocumentsResource {
		return $this->forUser($userId)->documents;
	}

	/** Signing-sessions resource for a specific user. Same rationale as above. */
	public function signingSessionsFor(string $userId): SigningSessionsResource {
		return $this->forUser($userId)->signingSessions;
	}

	/** Envelopes resource for a specific user. Same rationale as above. */
	public function envelopesFor(string $userId): EnvelopesResource {
		return $this->forUser($userId)->envelopes;
	}

	private function configForUser(string $userId): Config {
		$mode = $this->credentials->getTenantMode();
		if ($mode === Application::SETTING_TENANT_MODE_SHARED) {
			return $this->sharedConfig();
		}
		return $this->oauthConfig($userId);
	}

	/**
	 * Build a transient client from explicit credentials. Used by the admin
	 * "Test connection" button so credentials can be validated *before* the
	 * admin saves them — no need to commit bad creds to disk first.
	 */
	public function forCredentials(string $clientId, string $clientSecret): SignDocsBrasilClient {
		return new SignDocsBrasilClient(new Config(
			clientId: $clientId,
			clientSecret: $clientSecret,
			baseUrl: $this->credentials->getApiBaseUrl(),
		));
	}

	private function sharedConfig(): Config {
		$creds = $this->credentials->getTenantApiKey();
		if ($creds === null) {
			throw new \RuntimeException('Shared tenant mode is enabled but no tenant API key is configured.');
		}
		return new Config(
			clientId: $creds['clientId'],
			clientSecret: $creds['clientSecret'],
			baseUrl: $this->credentials->getApiBaseUrl(),
		);
	}

	private function oauthConfig(string $userId): Config {
		$token = $this->credentials->getUserOAuthToken($userId);
		if ($token === null) {
			throw new NotConnectedException('User has not linked a SignDocs Brasil account yet.');
		}
		// The PHP SDK accepts a clientId+clientSecret pair OR a private_key_jwt; for
		// per-user OAuth we store the user's clientId/clientSecret pair issued by
		// SignDocs after device-flow authorization.
		return new Config(
			clientId: $token['clientId'],
			clientSecret: $token['clientSecret'] ?? null,
			privateKey: $token['privateKey'] ?? null,
			kid: $token['kid'] ?? null,
			baseUrl: $this->credentials->getApiBaseUrl(),
		);
	}
}
