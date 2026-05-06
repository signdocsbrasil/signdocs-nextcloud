<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Controller;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Controller\SettingsController;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Settings endpoints worth pinning:
 *  - OAuth device-flow stubs return 501 (the v1.1 deferral) and never touch
 *    credentials.
 *  - disconnect() is a no-op when no user session exists, returning 401.
 *  - disconnect() with a session deletes the user's OAuth token.
 *  - setSignedFolder() persists per-user.
 *  - adminUpdate() persists tenant config and only writes credentials when
 *    SHARED mode is selected with both clientId and clientSecret present.
 */
class SettingsControllerTest extends TestCase {
	/** @var IRequest&MockObject */
	private $request;
	/** @var CredentialsService&MockObject */
	private $credentials;
	/** @var IUserSession&MockObject */
	private $userSession;
	/** @var SignDocsClientFactory&MockObject */
	private $clientFactory;
	/** @var IUser&MockObject */
	private $user;

	private SettingsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->credentials = $this->createMock(CredentialsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->clientFactory = $this->createMock(SignDocsClientFactory::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');

		$this->controller = new SettingsController(
			$this->request,
			$this->credentials,
			$this->userSession,
			$this->clientFactory,
		);
	}

	public function testStartDeviceFlowReturns501UntilSDKLandsDeviceCodeGrant(): void {
		$response = $this->controller->startDeviceFlow();
		self::assertSame(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
		self::assertSame('not_implemented', $response->getData()['error']);
	}

	public function testPollDeviceFlowReturns501(): void {
		$response = $this->controller->pollDeviceFlow('any-code');
		self::assertSame(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
	}

	public function testDisconnectRequiresAuthenticatedSession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->credentials->expects(self::never())->method('deleteUserOAuthToken');

		$response = $this->controller->disconnect();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testDisconnectRemovesCurrentUsersToken(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->credentials->expects(self::once())
			->method('deleteUserOAuthToken')
			->with('alice');

		$response = $this->controller->disconnect();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['ok']);
	}

	public function testSetSignedFolderRequiresAuth(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->credentials->expects(self::never())->method('setDefaultSignedFolder');

		$response = $this->controller->setSignedFolder('/Assinados');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testSetSignedFolderPersistsPerUser(): void {
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->credentials->expects(self::once())
			->method('setDefaultSignedFolder')
			->with('alice', '/MyDocs/Signed');

		$response = $this->controller->setSignedFolder('/MyDocs/Signed');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAdminUpdateInOAuthModeDoesNotWriteCredentials(): void {
		$this->credentials->expects(self::once())
			->method('setTenantMode')
			->with(Application::SETTING_TENANT_MODE_OAUTH);
		$this->credentials->expects(self::never())->method('storeTenantApiKey');
		$this->credentials->expects(self::never())->method('setWebhookSecret');

		$response = $this->controller->adminUpdate(
			tenantMode: Application::SETTING_TENANT_MODE_OAUTH,
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAdminUpdateInSharedModeStoresProvidedCredentials(): void {
		$this->credentials->expects(self::once())
			->method('setTenantMode')
			->with(Application::SETTING_TENANT_MODE_SHARED);
		$this->credentials->expects(self::once())
			->method('storeTenantApiKey')
			->with('client_id_x', 'client_secret_y');

		$response = $this->controller->adminUpdate(
			tenantMode: Application::SETTING_TENANT_MODE_SHARED,
			clientId: 'client_id_x',
			clientSecret: 'client_secret_y',
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAdminUpdatePersistsWebhookSecretWhenProvided(): void {
		$this->credentials->expects(self::once())
			->method('setWebhookSecret')
			->with('whsec_test');

		$response = $this->controller->adminUpdate(
			tenantMode: Application::SETTING_TENANT_MODE_OAUTH,
			webhookSecret: 'whsec_test',
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAdminUpdateSkipsEmptyWebhookSecret(): void {
		// Empty string from a "leave existing secret in place" form post must
		// NOT clobber the stored webhook secret.
		$this->credentials->expects(self::never())->method('setWebhookSecret');

		$response = $this->controller->adminUpdate(
			tenantMode: Application::SETTING_TENANT_MODE_OAUTH,
			webhookSecret: '',
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAdminUpdateInSharedModeWithoutCredentialsDoesNotWrite(): void {
		// Admin selecting SHARED mode but leaving the credential fields blank
		// (because they were already set previously) should not clobber the
		// stored values.
		$this->credentials->expects(self::once())->method('setTenantMode');
		$this->credentials->expects(self::never())->method('storeTenantApiKey');

		$response = $this->controller->adminUpdate(
			tenantMode: Application::SETTING_TENANT_MODE_SHARED,
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTestConnectionRequiresStoredCredsWhenBodyIsEmpty(): void {
		// Admin clicks "Testar conexão" without typing anything → should use
		// stored creds. If none are stored, return a 422 rather than 500.
		$this->credentials->method('getTenantApiKey')->willReturn(null);
		$this->clientFactory->expects(self::never())->method('forCredentials');

		$response = $this->controller->testConnection();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertFalse($response->getData()['ok']);
		self::assertSame('no_credentials', $response->getData()['error']);
	}

	public function testTestConnectionUsesProvidedCredsWhenSupplied(): void {
		// Admin types fresh creds and clicks Test before saving — they should
		// be used directly, NOT the stored creds (so the test can validate
		// before commit).
		$this->credentials->expects(self::never())->method('getTenantApiKey');
		$this->clientFactory->expects(self::once())
			->method('forCredentials')
			->with('cid_typed', 'csec_typed')
			->willThrowException(new \RuntimeException('test wiring proven'));

		$response = $this->controller->testConnection('cid_typed', 'csec_typed');

		// Either 502 (network failure simulated by exception) — what matters
		// here is forCredentials was called with the typed values.
		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		self::assertFalse($response->getData()['ok']);
	}

	public function testTestConnectionFallsBackToStoredCredsWhenBodyOmitted(): void {
		// Empty body → load saved creds, invoke factory with those.
		$this->credentials->method('getTenantApiKey')
			->willReturn(['clientId' => 'cid_saved', 'clientSecret' => 'csec_saved']);
		$this->credentials->method('getApiBaseUrl')->willReturn('https://api.signdocs.com.br');

		$this->clientFactory->expects(self::once())
			->method('forCredentials')
			->with('cid_saved', 'csec_saved')
			->willThrowException(new \RuntimeException('upstream timeout'));

		$response = $this->controller->testConnection();

		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		self::assertFalse($response->getData()['ok']);
		self::assertSame('auth_failed', $response->getData()['error']);
		self::assertSame('https://api.signdocs.com.br', $response->getData()['baseUrl']);
	}

	public function testTestConnectionEmptyStringTreatedAsAbsent(): void {
		// Admin clears the input fields and clicks Test → "" should not
		// reach the factory as bogus credentials. Treat as missing → fall
		// back to stored creds.
		$this->credentials->method('getTenantApiKey')
			->willReturn(['clientId' => 'cid_saved', 'clientSecret' => 'csec_saved']);

		$this->clientFactory->expects(self::once())
			->method('forCredentials')
			->with('cid_saved', 'csec_saved')
			->willThrowException(new \RuntimeException('e'));

		$response = $this->controller->testConnection('', '');
		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
	}
}
