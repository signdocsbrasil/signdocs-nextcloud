<?php

declare(strict_types=1);

namespace OCA\SignDocsBrasil\Tests\Unit\BackgroundJob;

use OCA\SignDocsBrasil\BackgroundJob\PollPendingSessions;
use OCA\SignDocsBrasil\Db\SigningSession;
use OCA\SignDocsBrasil\Db\SigningSessionMapper;
use OCA\SignDocsBrasil\Service\SignDocsClientFactory;
use OCA\SignDocsBrasil\Service\SigningSessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use SignDocsBrasil\Api\Errors\ConnectionException;
use SignDocsBrasil\Api\Errors\NotFoundException;
use SignDocsBrasil\Api\Errors\ProblemDetail;
use SignDocsBrasil\Api\HttpClient;
use SignDocsBrasil\Api\Resources\EnvelopesResource;
use SignDocsBrasil\Api\Resources\SigningSessionsResource;

/**
 * A 404 while polling is a terminal answer, not a failed attempt to get one.
 *
 * Treating it as transient leaves the row pending, so it is re-polled every run
 * for the life of the instance — ~288 wasted requests a day per stuck row, and
 * it never stops on its own. Observed on a real instance: three sessions aged
 * out past their TTL had produced 677 identical 404s.
 */
class PollPendingSessionsTest extends TestCase {
	private SigningSessionMapper $mapper;
	private SigningSessionService $service;
	private LoggerInterface $logger;
	private PollPendingSessions $job;

	/** @var \Throwable|string upstream behaviour for the next status call */
	private $upstream = 'PENDING';

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(SigningSessionMapper::class);
		$this->service = $this->createMock(SigningSessionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1785350000);

		$http = $this->createMock(HttpClient::class);
		$http->method('request')->willReturnCallback(function () {
			if ($this->upstream instanceof \Throwable) {
				throw $this->upstream;
			}
			return ['sessionId' => 'ss_1', 'status' => $this->upstream];
		});

		$factory = $this->createMock(SignDocsClientFactory::class);
		$factory->method('signingSessionsFor')->willReturn(new SigningSessionsResource($http));
		$factory->method('envelopesFor')->willReturn(new EnvelopesResource($http));

		$this->job = new PollPendingSessions($time, $this->mapper, $factory, $this->service, $this->logger);
	}

	private function givenPending(string $sessionId, array $metadata = ['kind' => 'session']): SigningSession {
		$entity = new SigningSession();
		$entity->setSessionId($sessionId);
		$entity->setFileId(136);
		$entity->setUserId('admin');
		$entity->setStatus('pending');
		$entity->setMetadata(json_encode($metadata, JSON_THROW_ON_ERROR));
		$this->mapper->method('findPendingOlderThan')->willReturn([$entity]);
		return $entity;
	}

	private function runJob(): void {
		$method = (new ReflectionClass(PollPendingSessions::class))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, null);
	}

	public function testA404MarksTheSessionExpiredSoPollingStops(): void {
		$this->givenPending('ss_gone');
		$this->upstream = new NotFoundException(new ProblemDetail('about:blank', 'Not Found', 404, 'Signing session not found'));

		// 'expired' is terminal, so the row drops out of findPendingOlderThan
		// and is never polled again.
		$this->service->expects(self::once())
			->method('applyStatusUpdate')
			->with('ss_gone', 'expired');

		$this->runJob();
	}

	public function testA404IsNotLoggedAsAFailure(): void {
		$this->givenPending('ss_gone');
		$this->upstream = new NotFoundException(new ProblemDetail('about:blank', 'Not Found', 404, 'Signing session not found'));

		// It is an expected end state, not an error to investigate — logging it
		// as a warning every 5 minutes is what produced 677 log lines.
		$this->logger->expects(self::never())->method('warning');
		$this->logger->expects(self::once())->method('info');

		$this->runJob();
	}

	public function testATransientFailureLeavesTheRowPendingForRetry(): void {
		$this->givenPending('ss_1');
		$this->upstream = new ConnectionException('cURL error 28: timed out');

		// Network trouble may well succeed next run, so the row must survive.
		$this->service->expects(self::never())->method('applyStatusUpdate');
		$this->logger->expects(self::once())->method('warning');

		$this->runJob();
	}

	public function testANormalStatusChangeStillPropagates(): void {
		$this->givenPending('ss_1');
		$this->upstream = 'COMPLETED';

		$this->service->expects(self::once())
			->method('applyStatusUpdate')
			->with('ss_1', 'COMPLETED');

		$this->runJob();
	}

	public function testAnUnchangedStatusIsNotRewritten(): void {
		$this->givenPending('ss_1');
		$this->upstream = 'pending';

		$this->service->expects(self::never())->method('applyStatusUpdate');

		$this->runJob();
	}

	public function testAnEmptyBatchDoesNothing(): void {
		$this->mapper->method('findPendingOlderThan')->willReturn([]);
		$this->service->expects(self::never())->method('applyStatusUpdate');

		$this->runJob();
	}
}
