<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Migration;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Migration\RepairDuplicateStatusTags;
use PHPUnit\Framework\TestCase;

/**
 * Badge precedence for a file with more than one signing attempt.
 *
 * Deliberately not "newest row wins": that would relabel a genuinely signed
 * document as cancelled the moment someone starts and abandons a second request
 * against it, which is exactly what happened on the first run of this step.
 */
class RepairDuplicateStatusTagsTest extends TestCase {
	public function testSingleStatusMapsDirectly(): void {
		self::assertSame(Application::TAG_ASSINADO, RepairDuplicateStatusTags::badgeForStatuses(['completed']));
		self::assertSame(Application::TAG_PENDENTE, RepairDuplicateStatusTags::badgeForStatuses(['pending']));
		self::assertSame(Application::TAG_CANCELADO, RepairDuplicateStatusTags::badgeForStatuses(['cancelled']));
	}

	public function testTerminalFailureStatesAllReadAsCancelled(): void {
		foreach (['cancelled', 'expired', 'failed'] as $status) {
			self::assertSame(Application::TAG_CANCELADO, RepairDuplicateStatusTags::badgeForStatuses([$status]), $status);
		}
	}

	public function testSignedOutranksALaterCancelledAttempt(): void {
		// The signature is a durable fact; abandoning a second request does not
		// undo it.
		self::assertSame(
			Application::TAG_ASSINADO,
			RepairDuplicateStatusTags::badgeForStatuses(['completed', 'cancelled']),
		);
		self::assertSame(
			Application::TAG_ASSINADO,
			RepairDuplicateStatusTags::badgeForStatuses(['cancelled', 'completed']),
		);
	}

	public function testPendingOutranksEverything(): void {
		// An open request is actionable, so it wins regardless of history.
		self::assertSame(
			Application::TAG_PENDENTE,
			RepairDuplicateStatusTags::badgeForStatuses(['completed', 'pending']),
		);
		self::assertSame(
			Application::TAG_PENDENTE,
			RepairDuplicateStatusTags::badgeForStatuses(['cancelled', 'pending', 'completed']),
		);
	}

	public function testUnknownStatusIsTreatedAsPending(): void {
		// Matches canonicalStatus: anything non-terminal keeps the row in play.
		self::assertSame(Application::TAG_PENDENTE, RepairDuplicateStatusTags::badgeForStatuses(['whatever']));
	}

	public function testOrderDoesNotMatter(): void {
		$a = RepairDuplicateStatusTags::badgeForStatuses(['pending', 'completed', 'cancelled']);
		$b = RepairDuplicateStatusTags::badgeForStatuses(['cancelled', 'completed', 'pending']);
		self::assertSame($a, $b);
	}
}
