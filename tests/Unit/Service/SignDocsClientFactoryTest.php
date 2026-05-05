<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCA\SignDocsBrasil\Service\NotConnectedException;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SignDocsBrasil\Api\SignDocsBrasilClient;

/**
 * Tenant-mode dispatch logic in the factory. The factory has two entry points:
 *  - forCurrentUser(): resolves IUserSession; for SHARED tenant mode falls
 *    back to the platform creds; for OAUTH mode requires per-user creds.
 *  - forUser($userId): used by background jobs that have no IUserSession
 *    context. Same dispatch but takes a userId directly.
 *
 * Real SDK instantiation happens at the end of either path. We assert the
 * outputs are SignDocsBrasilClient instances; the SDK constructor is
 * exercised but not network-bound so the test stays fast.
 */
class SignDocsClientFactoryTest extends TestCase {
	/** @var CredentialsService&MockObject */
	private $credentials;
	/** @var IUserSession&MockObject */
	private $userSession;
	/** @var IUser&MockObject */
	private $user;

	private SignDocsClientFactory $factory;

	protected function setUp(): void {
		parent::setUp();
		$this->credentials = $this->createMock(CredentialsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');

		$this->factory = new SignDocsClientFactory($this->credentials, $this->userSession);
	}

	public function testForCurrentUserInSharedModeIgnoresUserSession(): void {
		$this->credentials->method('getTenantMode')->willReturn(Application::SETTING_TENANT_MODE_SHARED);
		$this->credentials->method('getTenantApiKey')->willReturn(['clientId' => 'cid', 'clientSecret' => 'csec']);
		$this->credentials->method('getApiBaseUrl')->willReturn('https://api.signdocs.com.br');
		$this->userSession->expects(self::never())->method('getUser');

		$client = $this->factory->forCurrentUser();
		self::assertInstanceOf(SignDocsBrasilClient::class, $client);
	}

	public function testForCurrentUserInSharedModeWithoutCredsThrows(): void {
		$this->credentials->method('getTenantMode')->willReturn(Application::SETTING_TENANT_MODE_SHARED);
		$this->credentials->method('getTenantApiKey')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Shared tenant mode is enabled but no tenant API key is configured.');
		$this->factory->forCurrentUser();
	}

	public function testForCurrentUserInOAuthModeRequiresActiveSession(): void {
		$this->credentials->method('getTenantMode')->willReturn(Application::SETTING_TENANT_MODE_OAUTH);
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No active user session.');
		$this->factory->forCurrentUser();
	}

	public function testForCurrentUserInOAuthModeWithoutTokenThrowsNotConnected(): void {
		$this->credentials->method('getTenantMode')->willReturn(Application::SETTING_TENANT_MODE_OAUTH);
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->credentials->method('getUserOAuthToken')->with('alice')->willReturn(null);

		$this->expectException(NotConnectedException::class);
		$this->factory->forCurrentUser();
	}

	public function testForCurrentUserInOAuthModeBuildsClientFromStoredToken(): void {
		$this->credentials->method('getTenantMode')->willReturn(Application::SETTING_TENANT_MODE_OAUTH);
		$this->userSession->method('getUser')->willReturn($this->user);
		$this->credentials->method('getUserOAuthToken')
			->with('alice')
			->willReturn(['clientId' => 'alice_cid', 'clientSecret' => 'alice_csec']);
		$this->credentials->method('getApiBaseUrl')->willReturn('https://api.signdocs.com.br');

		$client = $this->factory->forCurrentUser();
		self::assertInstanceOf(SignDocsBrasilClient::class, $client);
	}

	public function testForUserInSharedModeReusesPlatformCreds(): void {
		// forUser() is the cron path — should NOT touch IUserSession at all.
		$this->credentials->method('getTenantMode')->willReturn(Application::SETTING_TENANT_MODE_SHARED);
		$this->credentials->method('getTenantApiKey')->willReturn(['clientId' => 'cid', 'clientSecret' => 'csec']);
		$this->credentials->method('getApiBaseUrl')->willReturn('https://api.signdocs.com.br');
		$this->userSession->expects(self::never())->method('getUser');

		$client = $this->factory->forUser('alice');
		self::assertInstanceOf(SignDocsBrasilClient::class, $client);
	}

	public function testForUserInOAuthModePullsTokenForExplicitUserId(): void {
		// Background-job path: explicit userId, no session in scope.
		$this->credentials->method('getTenantMode')->willReturn(Application::SETTING_TENANT_MODE_OAUTH);
		$this->credentials->method('getUserOAuthToken')
			->with('bob')
			->willReturn(['clientId' => 'bob_cid', 'clientSecret' => 'bob_csec']);
		$this->credentials->method('getApiBaseUrl')->willReturn('https://api.signdocs.com.br');
		$this->userSession->expects(self::never())->method('getUser');

		$client = $this->factory->forUser('bob');
		self::assertInstanceOf(SignDocsBrasilClient::class, $client);
	}

	public function testForUserInOAuthModeWithoutTokenThrowsNotConnected(): void {
		$this->credentials->method('getTenantMode')->willReturn(Application::SETTING_TENANT_MODE_OAUTH);
		$this->credentials->method('getUserOAuthToken')->with('bob')->willReturn(null);

		$this->expectException(NotConnectedException::class);
		$this->factory->forUser('bob');
	}
}
