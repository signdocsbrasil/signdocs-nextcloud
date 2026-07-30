<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\Service;

use OCA\SignDocsBrasil\AppInfo\Application;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\CredentialsService;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The three signdocs:* tags are mutually exclusive statuses, but the object
 * mapper only ever adds. Without an explicit strip, a signed document kept its
 * "Pendente" badge in Files forever and the Arquivos list showed both at once.
 */
class SigningSessionStatusTagTest extends TestCase {
	private ISystemTagManager $tagManager;
	private ISystemTagObjectMapper $tagObjectMapper;
	private SigningSessionService $service;
	private \ReflectionMethod $applyStatusTag;

	/** @var array<string, int> tag name → id */
	private const TAG_IDS = [
		Application::TAG_PENDENTE => 11,
		Application::TAG_ASSINADO => 22,
		Application::TAG_CANCELADO => 33,
	];

	protected function setUp(): void {
		parent::setUp();

		$this->tagManager = $this->createMock(ISystemTagManager::class);
		$this->tagObjectMapper = $this->createMock(ISystemTagObjectMapper::class);

		$this->service = new SigningSessionService(
			$this->createMock(SignDocsClientFactory::class),
			$this->createMock(SigningSessionMapper::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserSession::class),
			$this->tagManager,
			$this->tagObjectMapper,
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(CredentialsService::class),
			$this->createMock(IClientService::class),
		);

		$ref = new ReflectionClass(SigningSessionService::class);
		$this->applyStatusTag = $ref->getMethod('applyStatusTag');
		$this->applyStatusTag->setAccessible(true);
	}

	/** Every signdocs tag resolves; ids per self::TAG_IDS. */
	private function tagsExist(): void {
		$this->tagManager->method('getAllTags')
			->willReturnCallback(function ($visibilityFilter, $nameSearchPattern) {
				$id = self::TAG_IDS[$nameSearchPattern] ?? null;
				if ($id === null) {
					return [];
				}
				$tag = $this->createMock(ISystemTag::class);
				$tag->method('getId')->willReturn((string)$id);
				return [$tag];
			});
	}

	public function testCompletingStripsThePendingBadge(): void {
		$this->tagsExist();

		$this->tagObjectMapper->expects(self::once())
			->method('assignTags')
			->with('136', 'files', (string)self::TAG_IDS[Application::TAG_ASSINADO]);

		$unassigned = [];
		$this->tagObjectMapper->method('unassignTags')
			->willReturnCallback(function ($objId, $type, $tagId) use (&$unassigned) {
				$unassigned[] = $tagId;
			});

		$this->applyStatusTag->invoke($this->service, 136, Application::TAG_ASSINADO);

		self::assertEqualsCanonicalizing(
			[(string)self::TAG_IDS[Application::TAG_PENDENTE], (string)self::TAG_IDS[Application::TAG_CANCELADO]],
			$unassigned,
			'the other two status tags must come off',
		);
	}

	public function testTagBeingAppliedIsNeverStripped(): void {
		$this->tagsExist();

		$this->tagObjectMapper->method('unassignTags')
			->willReturnCallback(function ($objId, $type, $tagId) {
				self::assertNotSame(
					(string)self::TAG_IDS[Application::TAG_PENDENTE],
					$tagId,
					'must not unassign the tag it just assigned',
				);
			});

		$this->applyStatusTag->invoke($this->service, 140, Application::TAG_PENDENTE);
	}

	public function testCancellingStripsPendingAndSigned(): void {
		$this->tagsExist();

		$unassigned = [];
		$this->tagObjectMapper->method('unassignTags')
			->willReturnCallback(function ($objId, $type, $tagId) use (&$unassigned) {
				$unassigned[] = $tagId;
			});

		$this->applyStatusTag->invoke($this->service, 141, Application::TAG_CANCELADO);

		self::assertEqualsCanonicalizing(
			[(string)self::TAG_IDS[Application::TAG_PENDENTE], (string)self::TAG_IDS[Application::TAG_ASSINADO]],
			$unassigned,
		);
	}

	public function testMissingOtherTagIsNotFatal(): void {
		// A tag that was never created on this instance must not abort the
		// badge update — the file still needs its new status.
		$this->tagManager->method('getAllTags')
			->willReturnCallback(function ($visibilityFilter, $nameSearchPattern) {
				if ($nameSearchPattern === Application::TAG_ASSINADO) {
					$tag = $this->createMock(ISystemTag::class);
					$tag->method('getId')->willReturn('22');
					return [$tag];
				}
				throw new TagNotFoundException('no such tag');
			});

		$this->tagObjectMapper->expects(self::once())->method('assignTags');
		$this->tagObjectMapper->expects(self::never())->method('unassignTags');

		$this->applyStatusTag->invoke($this->service, 142, Application::TAG_ASSINADO);
	}

	public function testNothingIsStrippedWhenTheNewTagCannotBeApplied(): void {
		// assignTags threw, so the file keeps whatever badge it already had
		// rather than being left with none at all.
		$this->tagsExist();
		$this->tagObjectMapper->method('assignTags')
			->willThrowException(new TagNotFoundException('tag vanished'));
		$this->tagObjectMapper->expects(self::never())->method('unassignTags');

		$this->applyStatusTag->invoke($this->service, 143, Application::TAG_ASSINADO);
	}
}
