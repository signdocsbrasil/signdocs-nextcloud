<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Settings;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class Personal implements ISettings {
	public function __construct(
		private readonly CredentialsService $credentials,
		private readonly IInitialState $initialState,
		private readonly IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$user = $this->userSession->getUser();
		$userId = $user?->getUID() ?? '';
		$token = $userId !== '' ? $this->credentials->getUserOAuthToken($userId) : null;

		$this->initialState->provideInitialState('signdocs_personal', [
			'connected' => $token !== null,
			'signedFolder' => $userId !== '' ? $this->credentials->getDefaultSignedFolder($userId) : '/Assinados',
			'tenantMode' => $this->credentials->getTenantMode(),
		]);

		return new TemplateResponse(Application::APP_ID, 'settings/personal');
	}

	public function getSection(): string {
		return 'signdocs_brasil';
	}

	public function getPriority(): int {
		return 50;
	}
}
