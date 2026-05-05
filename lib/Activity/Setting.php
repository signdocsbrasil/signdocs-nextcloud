<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Activity;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCP\Activity\ActivitySettings;
use OCP\IL10N;

class Setting extends ActivitySettings {
	public function __construct(private readonly IL10N $l) {
	}

	public function getIdentifier(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('SignDocs Brasil signing events');
	}

	public function getPriority(): int {
		return 50;
	}

	public function getGroupIdentifier(): string {
		return 'files';
	}

	public function getGroupName(): string {
		return $this->l->t('Files');
	}

	public function canChangeStream(): bool {
		return true;
	}

	public function isDefaultEnabledStream(): bool {
		return true;
	}

	public function canChangeMail(): bool {
		return true;
	}

	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
