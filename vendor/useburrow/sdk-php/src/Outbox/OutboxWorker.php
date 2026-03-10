<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

use Burrow\Sdk\Client\BurrowClientInterface;
use Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException;
use Burrow\Sdk\Transport\Exception\TransportFailureException;
use Throwable;

final class OutboxWorker
{
    public function __construct(
        private readonly OutboxStoreInterface $store,
        private readonly BurrowClientInterface $client,
        private readonly int $maxAttempts = 5,
        private readonly BackoffStrategyInterface $backoffStrategy = new ExponentialBackoffStrategy()
    ) {
    }

    public function runOnce(int $limit = 50): OutboxWorkerResult
    {
        $records = $this->store->pullPending($limit);
        $sentCount = 0;
        $retryingCount = 0;
        $failedCount = 0;

        foreach ($records as $record) {
            try {
                $this->client->publishEvent($record->payload);
                $this->store->markSent($record->id);
                $sentCount++;
                continue;
            } catch (TransportFailureException $exception) {
                if ($this->shouldRetry($record->attemptCount)) {
                    $delay = $this->backoffStrategy->delaySecondsForAttempt($record->attemptCount + 1);
                    $this->store->markRetrying($record->id, $exception->getMessage(), $delay);
                    $retryingCount++;
                    continue;
                }

                $this->store->markFailed($record->id, $exception->getMessage());
                $failedCount++;
                continue;
            } catch (UnexpectedResponseStatusException $exception) {
                if ($exception->isRetryable() && $this->shouldRetry($record->attemptCount)) {
                    $delay = $this->backoffStrategy->delaySecondsForAttempt($record->attemptCount + 1);
                    $this->store->markRetrying($record->id, $exception->getMessage(), $delay);
                    $retryingCount++;
                    continue;
                }

                $this->store->markFailed($record->id, $exception->getMessage());
                $failedCount++;
                continue;
            } catch (Throwable $exception) {
                $this->store->markFailed($record->id, $exception->getMessage());
                $failedCount++;
            }
        }

        return new OutboxWorkerResult(
            processedCount: count($records),
            sentCount: $sentCount,
            retryingCount: $retryingCount,
            failedCount: $failedCount
        );
    }

    private function shouldRetry(int $currentAttemptCount): bool
    {
        return ($currentAttemptCount + 1) < $this->maxAttempts;
    }
}
