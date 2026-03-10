<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

use DateTimeImmutable;
use DateTimeZone;

final class InMemoryOutboxStore implements OutboxStoreInterface
{
    /** @var array<string, OutboxRecord> */
    private array $records = [];

    public function enqueue(string $eventKey, array $payload): OutboxRecord
    {
        $now = $this->utcNow();
        $id = bin2hex(random_bytes(8));
        $record = new OutboxRecord(
            id: $id,
            eventKey: $eventKey,
            status: OutboxStatus::PENDING,
            attemptCount: 0,
            payload: $payload,
            lastError: null,
            createdAt: $now,
            updatedAt: $now
        );
        $this->records[$id] = $record;
        return $record;
    }

    public function pullPending(int $limit = 50): array
    {
        $now = $this->utcNow();
        $rows = [];
        foreach ($this->records as $record) {
            if (
                $record->status === OutboxStatus::PENDING
                || (
                    $record->status === OutboxStatus::RETRYING
                    && ($record->nextAttemptAt === null || $record->nextAttemptAt <= $now)
                )
            ) {
                $rows[] = $record;
            }
            if (count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }

    public function markSent(string $id): void
    {
        $record = $this->records[$id] ?? null;
        if ($record === null) {
            return;
        }

        $now = $this->utcNow();
        $this->records[$id] = new OutboxRecord(
            id: $record->id,
            eventKey: $record->eventKey,
            status: OutboxStatus::SENT,
            attemptCount: $record->attemptCount + 1,
            payload: $record->payload,
            lastError: null,
            createdAt: $record->createdAt,
            updatedAt: $now,
            nextAttemptAt: null,
            sentAt: $now
        );
    }

    public function markRetrying(string $id, string $error, int $delaySeconds = 0): void
    {
        $nextAttemptAt = $delaySeconds > 0
            ? $this->utcNow()->modify(sprintf('+%d seconds', $delaySeconds))
            : null;

        $this->updateWithStatus($id, OutboxStatus::RETRYING, $error, $nextAttemptAt);
    }

    public function markFailed(string $id, string $error): void
    {
        $this->updateWithStatus($id, OutboxStatus::FAILED, $error, null);
    }

    private function updateWithStatus(
        string $id,
        string $status,
        string $error,
        ?DateTimeImmutable $nextAttemptAt
    ): void {
        $record = $this->records[$id] ?? null;
        if ($record === null) {
            return;
        }

        $this->records[$id] = new OutboxRecord(
            id: $record->id,
            eventKey: $record->eventKey,
            status: $status,
            attemptCount: $record->attemptCount + 1,
            payload: $record->payload,
            lastError: $error,
            createdAt: $record->createdAt,
            updatedAt: $this->utcNow(),
            nextAttemptAt: $nextAttemptAt,
            sentAt: null
        );
    }

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
