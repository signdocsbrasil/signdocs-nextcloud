<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCP\IConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CredentialsServiceTest extends TestCase {
	private CredentialsService $service;
	/** @var ICredentialsManager&MockObject */
	private $credManager;
	/** @var IConfig&MockObject */
	private $config;

	protected function setUp(): void {
		parent::setUp();
		$this->credManager = $this->createMock(ICredentialsManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->service = new CredentialsService($this->credManager, $this->config);
	}

	public function testStoreUserOAuthTokenUsesAppNamespacedKey(): void {
		$this->credManager->expects(self::once())
			->method('store')
			->with('alice', self::stringContains(Application::APP_ID), self::isType('array'));

		$this->service->storeUserOAuthToken('alice', ['clientId' => 'x', 'clientSecret' => 'y']);
	}

	public function testGetTenantModeFallsBackToOAuth(): void {
		$this->config->method('getAppValue')->willReturn(Application::SETTING_TENANT_MODE_OAUTH);
		self::assertSame(Application::SETTING_TENANT_MODE_OAUTH, $this->service->getTenantMode());
	}

	public function testSetTenantModeRejectsUnknownValue(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setTenantMode('mystery_mode');
	}

	public function testGetWebhookSecretReturnsNullWhenEmpty(): void {
		$this->config->method('getAppValue')->willReturn('');
		self::assertNull($this->service->getWebhookSecret());
	}
}
