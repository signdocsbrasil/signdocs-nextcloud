<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Migration;

use Closure;
use OCA\SignDocsBrasil\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagAlreadyExistsException;
use Psr\Log\LoggerInterface;

class Version000100Date20260505000000 extends SimpleMigrationStep {

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
	 *
	 * Pulling ISystemTagManager via Server::get() inside the method (not via
	 * constructor injection) because NC's MigrationService does instantiate
	 * SimpleMigrationStep subclasses through the DI container, but in some
	 * paths it falls back to `new $class()` which would crash on a typed
	 * required constructor parameter and silently abort the migration step.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$tagManager = Server::get(ISystemTagManager::class);
		$logger = Server::get(LoggerInterface::class);

		foreach ([
			Application::TAG_PENDENTE,
			Application::TAG_ASSINADO,
			Application::TAG_CANCELADO,
		] as $tagName) {
			try {
				$tagManager->createTag($tagName, true, false);
				$output->info('Created SystemTag: ' . $tagName);
			} catch (TagAlreadyExistsException) {
				// idempotent — fine on re-runs and upgrades
			} catch (\Throwable $e) {
				$logger->warning('SignDocs Brasil: failed to create SystemTag ' . $tagName, ['exception' => $e]);
				$output->warning('Failed to create SystemTag ' . $tagName . ': ' . $e->getMessage());
			}
		}
	}
}
