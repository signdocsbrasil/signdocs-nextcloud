<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Settings;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private readonly CredentialsService $credentials,
		private readonly IInitialState $initialState,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		$webhookUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('signdocs_brasil.webhook.receive')
		);

		$this->initialState->provideInitialState('signdocs_admin', [
			'tenantMode' => $this->credentials->getTenantMode(),
			'apiBaseUrl' => $this->credentials->getApiBaseUrl(),
			'tenantConfigured' => $this->credentials->getTenantApiKey() !== null,
			'webhookConfigured' => $this->credentials->getWebhookSecret() !== null,
			'webhookUrl' => $webhookUrl,
		]);

		return new TemplateResponse(Application::APP_ID, 'settings/admin');
	}

	public function getSection(): string {
		return 'signdocs_brasil';
	}

	public function getPriority(): int {
		return 50;
	}
}
