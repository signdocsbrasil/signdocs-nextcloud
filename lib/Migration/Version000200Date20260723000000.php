<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds an indexed `transaction_id` column so webhook events keyed by the
 * SignDocs transaction id (TRANSACTION.* family) can be correlated to a local
 * mirror row. Single-signer flows previously stored the transaction id only
 * inside the `metadata` JSON blob, which is not queryable — so every
 * TRANSACTION.COMPLETED webhook was rejected with HTTP 400 (malformed_event).
 */
class Version000200Date20260723000000 extends SimpleMigrationStep {

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('signdocs_sessions')) {
			return null;
		}

		$table = $schema->getTable('signdocs_sessions');
		if (!$table->hasColumn('transaction_id')) {
			$table->addColumn('transaction_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}
		if (!$table->hasIndex('sdb_txn_id_idx')) {
			$table->addIndex(['transaction_id'], 'sdb_txn_id_idx');
		}

		return $schema;
	}

	/**
	 * Backfill `transaction_id` from the `metadata` JSON for existing
	 * single-signer rows, so sessions created before this migration can still
	 * reconcile via webhook.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->select('id', 'metadata')
			->from('signdocs_sessions')
			->where($select->expr()->isNull('transaction_id'))
			->andWhere($select->expr()->isNotNull('metadata'));
		$result = $select->executeQuery();

		$count = 0;
		while ($row = $result->fetch()) {
			$meta = json_decode((string)$row['metadata'], true);
			$txn = is_array($meta) ? ($meta['transactionId'] ?? null) : null;
			if (!is_string($txn) || $txn === '') {
				continue;
			}
			$update = $this->db->getQueryBuilder();
			$update->update('signdocs_sessions')
				->set('transaction_id', $update->createNamedParameter($txn, IQueryBuilder::PARAM_STR))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
			$count++;
		}
		$result->closeCursor();

		if ($count > 0) {
			$output->info("Backfilled transaction_id for {$count} signing session(s).");
		}
	}
}
