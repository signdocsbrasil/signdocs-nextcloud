<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\SignDocsBrasil\AppInfo\Application;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class FilesLoadAdditionalScriptsListener implements IEventListener {
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly IUserSession $userSession,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}

		// Hand the front-end the supported MIME types and the API base path so
		// the right-click menu can decide where to attach the action.
		//
		// `userEmail` is the current user's own profile address, echoed back to
		// that same user. It becomes the request owner in SigningSessionService,
		// which is what makes SignDocs dispatch the invite emails — so the
		// confirmation step needs it to say whether invites will actually go out.
		$user = $this->userSession->getUser();
		$this->initialState->provideInitialState('signdocs', [
			'apiBase' => '/apps/' . Application::APP_ID . '/api/v1',
			'supportedMimeTypes' => [
				'application/pdf',
				'application/vnd.oasis.opendocument.text',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/msword',
			],
			'userEmail' => $user?->getEMailAddress() ?? '',
		]);

		Util::addScript(Application::APP_ID, 'signdocs-files-action');
		Util::addStyle(Application::APP_ID, 'signdocs-files-action');
	}
}
