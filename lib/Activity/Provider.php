<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Activity;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class Provider implements IProvider {
	public const SUBJECT_SENT = 'sdb_sent';
	public const SUBJECT_COMPLETED = 'sdb_completed';
	public const SUBJECT_CANCELLED = 'sdb_cancelled';

	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID) {
			throw new \InvalidArgumentException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		$params = $event->getSubjectParameters();
		$fileName = $params['fileName'] ?? 'document';

		$event->setIcon($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));

		switch ($event->getSubject()) {
			case self::SUBJECT_SENT:
				$event->setParsedSubject($l->t('Você enviou "%s" para assinatura', [$fileName]));
				break;
			case self::SUBJECT_COMPLETED:
				$event->setParsedSubject($l->t('"%s" foi assinado por todos os signatários', [$fileName]));
				break;
			case self::SUBJECT_CANCELLED:
				$event->setParsedSubject($l->t('Assinatura de "%s" cancelada', [$fileName]));
				break;
			default:
				throw new \InvalidArgumentException('Unknown activity subject: ' . $event->getSubject());
		}

		return $event;
	}
}
