<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Migration;

use Closure;
use OCA\SignDocsBrasil\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAlreadyExistsException;

class Version000100Date20260505000000 extends SimpleMigrationStep {

	public function __construct(
		private readonly ISystemTagManager $tagManager,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('signdocs_sessions')) {
			$table = $schema->createTable('signdocs_sessions');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('session_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'pending',
			]);
			$table->addColumn('signed_file_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('metadata', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['session_id'], 'sdb_session_id_uniq');
			$table->addIndex(['user_id', 'created_at'], 'sdb_user_created_idx');
			$table->addIndex(['file_id'], 'sdb_file_idx');
			$table->addIndex(['status', 'updated_at'], 'sdb_status_updated_idx');
		}

		return $schema;
	}

	/**
	 * Create the three signing-status SystemTags.
	 *
	 * postSchemaChange runs on every app:enable (fresh installs and upgrades),
	 * which is what we want — NC's <repair-steps> only run on `occ maintenance:repair`
	 * and during version upgrades, NOT on first install. Putting tag creation
	 * here guarantees they exist before the Files action ever needs them.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach ([
			Application::TAG_PENDENTE,
			Application::TAG_ASSINADO,
			Application::TAG_CANCELADO,
		] as $tagName) {
			try {
				$this->tagManager->createTag($tagName, true, false);
				$output->info('Created SystemTag: ' . $tagName);
			} catch (TagAlreadyExistsException) {
				// idempotent — fine on re-runs and upgrades
			}
		}
	}
}
