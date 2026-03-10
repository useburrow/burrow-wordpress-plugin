<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Closure;
use Burrow\Sdk\Client\BackfillOptions;
use Burrow\Sdk\Client\BackfillProgressUpdate;
use Burrow\Sdk\Client\BurrowClient;
use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\BackfillWindow;
use Burrow\Sdk\Transport\ConcurrentHttpTransportInterface;
use Burrow\Sdk\Transport\HttpResponse;
use Burrow\Sdk\Transport\HttpTransportInterface;
use PHPUnit\Framework\TestCase;

final class BackfillClientTest extends TestCase
{
    public function testRequestSerializationIncludesEventsAndBackfillWindow(): void
    {
        $request = new BackfillEventsRequest(
            events: [
                ['event' => 'forms.submission.received', 'clientId' => 'cli_123', 'timestamp' => '2026-03-01T12:00:00.000Z'],
            ],
            backfill: new BackfillWindow(
                windowStart: '2026-03-01T00:00:00.000Z',
                cursor: 'cursor_123',
                windowEnd: '2026-03-02T00:00:00.000Z',
                source: 'wordpress-plugin'
            )
        );

        $payload = $request->toArray();
        $this->assertArrayHasKey('events', $payload);
        $this->assertArrayHasKey('backfill', $payload);
        $this->assertSame('cursor_123', $payload['backfill']['cursor']);
        $this->assertSame('2026-03-01T00:00:00.000Z', $payload['backfill']['windowStart']);
    }

    public function testChunksBackfillAtOneHundredAndUsesDefaultConcurrencyFour(): void
    {
        $progress = [];
        $transport = new InspectingBackfillTransport();
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $events = [];
        for ($index = 1; $index <= 205; $index++) {
            $events[] = $this->makeBackfillEvent('sub_' . $index, '2026-03-01T12:00:00.000Z');
        }

        $client->backfillEvents(
            new BackfillEventsRequest(
                events: $events,
                backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
            ),
            null,
            static function (BackfillProgressUpdate $update) use (&$progress): void {
                $progress[] = $update;
            }
        );

        $this->assertCount(3, $transport->payloads);
        $this->assertCount(100, $transport->payloads[0]['events']);
        $this->assertCount(100, $transport->payloads[1]['events']);
        $this->assertCount(5, $transport->payloads[2]['events']);

        $running = array_values(array_filter($progress, static fn (BackfillProgressUpdate $update): bool => $update->status === 'running'));
        $this->assertNotEmpty($running);
        $this->assertSame(3, $running[0]->runningCount);
    }

    public function testRetriesOn429WithRetryAfterHeader(): void
    {
        $transport = new Retry429BackfillTransport();
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $result = $client->backfillEvents(
            new BackfillEventsRequest(
                events: [$this->makeBackfillEvent('sub_123', '2026-03-01T12:00:00.000Z')],
                backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
            ),
            new BackfillOptions(maxAttempts: 3, baseDelayMilliseconds: 1, maxDelayMilliseconds: 1)
        );

        $this->assertSame(2, $transport->callCount);
        $this->assertSame(1, $result->acceptedCount);
        $this->assertSame(0, $result->rejectedCount);
        $this->assertSame(0, $result->validationRejectedCount);
    }

    public function testUsesConcurrentTransportWhenAvailable(): void
    {
        $transport = new ConcurrentInspectingBackfillTransport();
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $events = [];
        for ($index = 1; $index <= 105; $index++) {
            $events[] = $this->makeBackfillEvent('sub_' . $index, '2026-03-01T12:00:00.000Z') + ['externalEventId' => 'evt_' . $index];
        }

        $result = $client->backfillEvents(
            new BackfillEventsRequest(
                events: $events,
                backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
            ),
            new BackfillOptions(batchSize: 100, concurrency: 4, maxAttempts: 2)
        );

        $this->assertSame(1, $transport->concurrentCallCount);
        $this->assertSame(2, $transport->windowSizes[0]);
        $this->assertSame(105, $result->acceptedCount);
    }

    public function testReturnsPartialAcceptedAndRejectedItems(): void
    {
        $transport = new InspectingBackfillTransport(
            static fn (): HttpResponse => new HttpResponse(
                status: 207,
                body: [
                    'accepted' => [['externalEventId' => 'evt_1']],
                    'rejected' => [['externalEventId' => 'evt_2', 'reason' => 'invalid payload']],
                    'summary' => [
                        'requestedCount' => 2,
                        'acceptedCount' => 1,
                        'rejectedCount' => 1,
                    ],
                    'backfill' => ['cursor' => 'cursor_final'],
                ],
                raw: '{"partial":true}'
            )
        );
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $result = $client->backfillEvents(new BackfillEventsRequest(
            events: [
                $this->makeBackfillEvent('sub_1', '2026-03-01T12:00:00.000Z') + ['externalEventId' => 'evt_1'],
                $this->makeBackfillEvent('sub_2', '2026-03-01T12:00:00.000Z') + ['externalEventId' => 'evt_2'],
            ],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
        ));

        $this->assertSame(2, $result->requestedCount);
        $this->assertSame(1, $result->acceptedCount);
        $this->assertSame(1, $result->rejectedCount);
        $this->assertSame(0, $result->validationRejectedCount);
        $this->assertSame('cursor_final', $result->latestCursor);
        $this->assertSame('evt_2', $result->rejected[0]['externalEventId']);
    }

    public function testRejectsMissingTimestampAndContinuesWithValidRecords(): void
    {
        $transport = new InspectingBackfillTransport();
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $result = $client->backfillEvents(new BackfillEventsRequest(
            events: [
                $this->makeBackfillEvent('sub_1', '2026-03-01T12:00:00.000Z'),
                ['event' => 'forms.submission.received', 'submissionId' => 'sub_missing'],
            ],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
        ));

        $this->assertCount(1, $transport->payloads);
        $this->assertCount(1, $transport->payloads[0]['events']);
        $this->assertSame(1, $result->validationRejectedCount);
        $this->assertSame('missing_timestamp', $result->validationRejections[0]['reason']);
        $this->assertSame(1, $result->rejectedCount);
    }

    public function testRejectsInvalidTimestampAndDoesNotFallbackToNow(): void
    {
        $transport = new InspectingBackfillTransport();
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $result = $client->backfillEvents(new BackfillEventsRequest(
            events: [
                $this->makeBackfillEvent('sub_valid', '2026-03-01T12:00:00.000Z'),
                $this->makeBackfillEvent('sub_invalid', 'not-a-date'),
            ],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
        ));

        $this->assertCount(1, $transport->payloads);
        $sentEvent = $transport->payloads[0]['events'][0];
        $this->assertSame('2026-03-01T12:00:00.000Z', $sentEvent['timestamp']);
        $this->assertSame(1, $result->validationRejectedCount);
        $this->assertSame('invalid_timestamp', $result->validationRejections[0]['reason']);
        $this->assertSame(1, $result->rejectedCount);
    }

    /**
     * @return array<string,mixed>
     */
    private function makeBackfillEvent(string $submissionId, string $timestamp): array
    {
        return [
            'organizationId' => 'org_123',
            'clientId' => 'cli_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'timestamp' => $timestamp,
            'submissionId' => $submissionId,
        ];
    }
}

final class InspectingBackfillTransport implements HttpTransportInterface
{
    /** @var list<array<string,mixed>> */
    public array $payloads = [];

    /**
     * @param Closure():HttpResponse|null $responseFactory
     */
    public function __construct(private readonly ?Closure $responseFactory = null)
    {
    }

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        $this->payloads[] = $payload;

        if ($this->responseFactory !== null) {
            return ($this->responseFactory)();
        }

        return new HttpResponse(
            status: 200,
            body: [
                'accepted' => $payload['events'] ?? [],
                'rejected' => [],
                'summary' => [
                    'requestedCount' => is_array($payload['events'] ?? null) ? count($payload['events']) : 0,
                    'acceptedCount' => is_array($payload['events'] ?? null) ? count($payload['events']) : 0,
                    'rejectedCount' => 0,
                ],
                'backfill' => ['cursor' => 'cursor_' . count($this->payloads)],
            ],
            raw: '{"ok":true}'
        );
    }
}

final class Retry429BackfillTransport implements HttpTransportInterface
{
    public int $callCount = 0;

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        $this->callCount++;
        if ($this->callCount === 1) {
            return new HttpResponse(
                status: 429,
                body: ['error' => 'rate limited'],
                raw: '{"error":"rate limited"}',
                headers: ['retry-after' => '0']
            );
        }

        return new HttpResponse(
            status: 200,
            body: [
                'accepted' => [['externalEventId' => 'evt_1']],
                'rejected' => [],
                'summary' => [
                    'requestedCount' => 1,
                    'acceptedCount' => 1,
                    'rejectedCount' => 0,
                ],
            ],
            raw: '{"ok":true}'
        );
    }
}

final class ConcurrentInspectingBackfillTransport implements ConcurrentHttpTransportInterface
{
    public int $concurrentCallCount = 0;

    /** @var list<int> */
    public array $windowSizes = [];

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        return new HttpResponse(
            status: 200,
            body: [
                'accepted' => $payload['events'] ?? [],
                'rejected' => [],
            ],
            raw: '{"ok":true}'
        );
    }

    public function postConcurrent(array $requests): array
    {
        $this->concurrentCallCount++;
        $this->windowSizes[] = count($requests);

        $responses = [];
        foreach ($requests as $request) {
            $responses[] = new HttpResponse(
                status: 200,
                body: [
                    'accepted' => $request['payload']['events'] ?? [],
                    'rejected' => [],
                ],
                raw: '{"ok":true}'
            );
        }

        return $responses;
    }
}
