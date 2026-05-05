<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<SigningSession>
 */
class SigningSessionMapper extends QBMapper {
	public const TABLE = 'signdocs_sessions';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, SigningSession::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findBySessionId(string $sessionId): SigningSession {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_STR)));
		return $this->findEntity($qb);
	}

	/**
	 * @return SigningSession[]
	 */
	public function findByUser(string $userId, ?int $limit = 50, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit ?? 50);
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}
		return $this->findEntities($qb);
	}

	/**
	 * @return SigningSession[]
	 */
	public function findByFile(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Pending sessions older than the given timestamp — used by the polling job
	 * to reconcile state for NC instances behind firewalls that can't receive
	 * webhooks.
	 *
	 * @return SigningSession[]
	 */
	public function findPendingOlderThan(int $timestamp, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending', IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)))
			->orderBy('updated_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
