<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Migration;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Repairs files carrying more than one signdocs:* badge.
 *
 * Until the applyStatusTag fix, every status change *added* a tag and never
 * removed the previous one, so a completed document ended up tagged both
 * signdocs:pendente and signdocs:assinado and the Files list showed it as still
 * pending. The fix stops new accumulation but cannot clean up what installs
 * already have, hence this step.
 *
 * A file can be sent for signature more than once, so the badge is chosen by
 * precedence across all of its rows rather than by taking the newest one:
 *
 *   pendente > assinado > cancelado
 *
 * A pending request is actionable and outranks everything. Having been signed is
 * a durable fact that a later cancelled attempt does not undo — picking "newest
 * row wins" would relabel a genuinely signed document as cancelled the moment
 * someone starts and abandons a second request against it.
 *
 * Idempotent: a second run reports no changes.
 */
class RepairDuplicateStatusTags implements IRepairStep {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ISystemTagManager $tagManager,
		private readonly ISystemTagObjectMapper $tagObjectMapper,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'Repair SignDocs Brasil files carrying more than one status tag';
	}

	public function run(IOutput $output): void {
		$tagIds = $this->resolveTagIds();
		if (empty($tagIds)) {
			$output->info('No signdocs status tags exist yet — nothing to repair.');
			return;
		}

		$assigned = 0;
		$stripped = 0;
		foreach ($this->badgePerFile() as $fileId => $keep) {
			$objectId = (string)$fileId;

			try {
				if (isset($tagIds[$keep]) && !$this->tagObjectMapper->haveTag($objectId, 'files', $tagIds[$keep])) {
					$this->tagObjectMapper->assignTags($objectId, 'files', $tagIds[$keep]);
					$assigned++;
				}
				foreach ($tagIds as $name => $id) {
					if ($name === $keep) {
						continue;
					}
					if ($this->tagObjectMapper->haveTag($objectId, 'files', $id)) {
						$this->tagObjectMapper->unassignTags($objectId, 'files', $id);
						$stripped++;
					}
				}
			} catch (TagNotFoundException $e) {
				// A tag vanished between resolve and use — skip the file rather
				// than abort the whole upgrade.
				$this->logger->warning('Skipped status-tag repair for a file', [
					'fileId' => $fileId,
					'exception' => $e,
				]);
			}
		}

		$output->info(sprintf(
			'Status tags repaired: %d badge(s) added, %d stale badge(s) removed.',
			$assigned,
			$stripped,
		));
	}

	/**
	 * @return array<string, string> tag name => tag id, for tags that exist
	 */
	private function resolveTagIds(): array {
		$ids = [];
		foreach ([
			Application::TAG_PENDENTE,
			Application::TAG_ASSINADO,
			Application::TAG_CANCELADO,
		] as $name) {
			try {
				$found = $this->tagManager->getAllTags(null, $name);
				$tag = $found[array_key_first($found)] ?? null;
				if ($tag !== null) {
					$ids[$name] = $tag->getId();
				}
			} catch (TagNotFoundException) {
				// Never created on this instance — nothing to assign or strip.
			}
		}
		return $ids;
	}

	/**
	 * The single badge each tracked file should carry, by precedence across all
	 * of that file's rows: pendente > assinado > cancelado.
	 *
	 * @return array<int, string> fileId => tag name
	 */
	private function badgePerFile(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'status')
			->from(SigningSessionMapper::TABLE);

		$statuses = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$statuses[(int)$row['file_id']][] = (string)$row['status'];
		}
		$result->closeCursor();

		return array_map(
			static fn (array $rows): string => self::badgeForStatuses($rows),
			$statuses,
		);
	}

	/**
	 * Winning badge for one file's set of mirror statuses.
	 *
	 * @param string[] $statuses
	 */
	public static function badgeForStatuses(array $statuses): string {
		$rank = [
			Application::TAG_CANCELADO => 0,
			Application::TAG_ASSINADO => 1,
			Application::TAG_PENDENTE => 2,
		];

		$winner = Application::TAG_CANCELADO;
		foreach ($statuses as $status) {
			$candidate = match ($status) {
				'completed' => Application::TAG_ASSINADO,
				'cancelled', 'expired', 'failed' => Application::TAG_CANCELADO,
				default => Application::TAG_PENDENTE,
			};
			if ($rank[$candidate] > $rank[$winner]) {
				$winner = $candidate;
			}
		}
		return $winner;
	}
}
