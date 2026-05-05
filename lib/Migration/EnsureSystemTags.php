<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Migration;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAlreadyExistsException;

class EnsureSystemTags implements IRepairStep {
	public function __construct(
		private readonly ISystemTagManager $tagManager,
	) {
	}

	public function getName(): string {
		return 'Ensure SignDocs Brasil status SystemTags exist';
	}

	public function run(IOutput $output): void {
		foreach ([
			Application::TAG_PENDENTE,
			Application::TAG_ASSINADO,
			Application::TAG_CANCELADO,
		] as $tagName) {
			try {
				$this->tagManager->createTag($tagName, true, false);
				$output->info('Created SystemTag: ' . $tagName);
			} catch (TagAlreadyExistsException) {
				// idempotent — fine
			}
		}
	}
}
