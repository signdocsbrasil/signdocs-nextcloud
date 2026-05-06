<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\SignDocsBrasil\Listener\FilesLoadAdditionalScriptsListener;
use OCA\SignDocsBrasil\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Notification\IManager as INotificationManager;

class Application extends App implements IBootstrap {
	public const APP_ID = 'signdocs_brasil';

	public const TAG_PENDENTE = 'signdocs:pendente';
	public const TAG_ASSINADO = 'signdocs:assinado';
	public const TAG_CANCELADO = 'signdocs:cancelado';

	public const SETTING_TENANT_MODE_OAUTH = 'oauth';
	public const SETTING_TENANT_MODE_SHARED = 'shared';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			LoadAdditionalScriptsEvent::class,
			FilesLoadAdditionalScriptsListener::class
		);
	}

	public function boot(IBootContext $context): void {
		/** @var INotificationManager $notificationManager */
		$notificationManager = $context->getServerContainer()->get(INotificationManager::class);
		$notificationManager->registerNotifierService(Notifier::class);
	}
}
