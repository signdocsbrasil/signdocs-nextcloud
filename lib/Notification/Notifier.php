<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Notification;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'SignDocs Brasil';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new \InvalidArgumentException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$fileName = $params['fileName'] ?? 'document';

		switch ($notification->getSubject()) {
			case 'session_completed':
				$notification
					->setParsedSubject($l->t('Documento "%s" foi assinado', [$fileName]))
					->setParsedMessage($l->t('Todos os signatários assinaram. O documento assinado já está disponível.'))
					->setIcon($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
				return $notification;

			case 'session_cancelled':
				$notification
					->setParsedSubject($l->t('Assinatura de "%s" foi cancelada', [$fileName]))
					->setParsedMessage($params['reason'] ?? '')
					->setIcon($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
				return $notification;

			case 'session_expired':
				$notification
					->setParsedSubject($l->t('Assinatura de "%s" expirou', [$fileName]))
					->setIcon($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
				return $notification;

			default:
				throw new AlreadyProcessedException();
		}
	}
}
