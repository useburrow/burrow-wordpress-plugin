<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Client\BurrowClientInterface;
use Burrow\Sdk\Client\BackfillEventsResult;
use Burrow\Sdk\Client\BackfillOptions;
use Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException;
use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\FormsContractsResponse;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Outbox\ExponentialBackoffStrategy;
use Burrow\Sdk\Outbox\InMemoryOutboxStore;
use Burrow\Sdk\Outbox\OutboxStatus;
use Burrow\Sdk\Outbox\OutboxWorker;
use Burrow\Sdk\Transport\Exception\TransportFailureException;
use Burrow\Sdk\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutboxWorkerTest extends TestCase
{
    public function testMarksRecordSentOnSuccessfulPublish(): void
    {
        $store = new InMemoryOutboxStore();
        $record = $store->enqueue('event_1', ['event' => 'forms.submission.received']);
        $worker = new OutboxWorker($store, new SequenceClient([new HttpResponse(200, ['ok' => true], '{"ok":true}')]));

        $result = $worker->runOnce();
        $rows = $store->pullPending();

        $this->assertSame(1, $result->processedCount);
        $this->assertSame(1, $result->sentCount);
        $this->assertCount(0, $rows);

        $sentRecord = $this->readRecord($store, $record->id);
        $this->assertSame(OutboxStatus::SENT, $sentRecord->status);
    }

    public function testRetriesOnTransportFailure(): void
    {
        $store = new InMemoryOutboxStore();
        $record = $store->enqueue('event_1', ['event' => 'forms.submission.received']);
        $worker = new OutboxWorker(
            $store,
            new SequenceClient([new TransportFailureException('connection timeout')]),
            maxAttempts: 5,
            backoffStrategy: new ExponentialBackoffStrategy(baseDelaySeconds: 1, multiplier: 2, maxDelaySeconds: 10)
        );

        $result = $worker->runOnce();
        $updated = $this->readRecord($store, $record->id);

        $this->assertSame(1, $result->retryingCount);
        $this->assertSame(OutboxStatus::RETRYING, $updated->status);
        $this->assertSame(1, $updated->attemptCount);
        $this->assertNotNull($updated->nextAttemptAt);
    }

    public function testFailsAfterMaxAttemptsForRetryableErrors(): void
    {
        $store = new InMemoryOutboxStore();
        $record = $store->enqueue('event_1', ['event' => 'forms.submission.received']);
        $store->markRetrying($record->id, 'attempt one failed', 0);
        $store->markRetrying($record->id, 'attempt two failed', 0);

        $exception = new UnexpectedResponseStatusException(
            '/api/v1/events',
            new HttpResponse(503, ['error' => 'unavailable'], '{"error":"unavailable"}')
        );
        $worker = new OutboxWorker($store, new SequenceClient([$exception]), maxAttempts: 3);

        $result = $worker->runOnce();
        $updated = $this->readRecord($store, $record->id);

        $this->assertSame(1, $result->failedCount);
        $this->assertSame(OutboxStatus::FAILED, $updated->status);
        $this->assertSame(3, $updated->attemptCount);
    }

    public function testFailsImmediatelyOnNonRetryableError(): void
    {
        $store = new InMemoryOutboxStore();
        $record = $store->enqueue('event_1', ['event' => 'forms.submission.received']);
        $worker = new OutboxWorker($store, new SequenceClient([new RuntimeException('validation failed')]));

        $result = $worker->runOnce();
        $updated = $this->readRecord($store, $record->id);

        $this->assertSame(1, $result->failedCount);
        $this->assertSame(OutboxStatus::FAILED, $updated->status);
        $this->assertSame(1, $updated->attemptCount);
    }

    private function readRecord(InMemoryOutboxStore $store, string $id): object
    {
        $reflection = new \ReflectionClass($store);
        $property = $reflection->getProperty('records');
        $property->setAccessible(true);
        $records = $property->getValue($store);
        self::assertIsArray($records);
        self::assertArrayHasKey($id, $records);

        return $records[$id];
    }
}

final class SequenceClient implements BurrowClientInterface
{
    /** @var list<HttpResponse|\Throwable> */
    private array $sequence;

    /**
     * @param list<HttpResponse|\Throwable> $sequence
     */
    public function __construct(array $sequence)
    {
        $this->sequence = $sequence;
    }

    public function discover(OnboardingDiscoveryRequest $request): HttpResponse
    {
        return $this->next();
    }

    public function link(OnboardingLinkRequest $request): HttpResponse
    {
        return $this->next();
    }

    public function submitFormsContract(FormsContractSubmissionRequest $request): FormsContractsResponse
    {
        return FormsContractsResponse::fromResponseBody($this->next()->body);
    }

    public function fetchFormsContracts(string $projectId, string $platform): FormsContractsResponse
    {
        return FormsContractsResponse::fromResponseBody($this->next()->body);
    }

    public function publishEvent(array $event): HttpResponse
    {
        return $this->next();
    }

    public function backfillEvents(
        BackfillEventsRequest $request,
        ?BackfillOptions $options = null,
        ?callable $progressCallback = null
    ): BackfillEventsResult {
        return new BackfillEventsResult(
            accepted: [],
            rejected: [],
            requestedCount: 0,
            acceptedCount: 0,
            rejectedCount: 0,
            validationRejectedCount: 0,
            validationRejections: [],
            latestCursor: null
        );
    }

    private function next(): HttpResponse
    {
        $item = array_shift($this->sequence);
        if ($item === null) {
            throw new RuntimeException('No response left in sequence.');
        }

        if ($item instanceof \Throwable) {
            throw $item;
        }

        return $item;
    }
}
