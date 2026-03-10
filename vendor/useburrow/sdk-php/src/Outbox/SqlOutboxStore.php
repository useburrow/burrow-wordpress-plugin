<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class SqlOutboxStore implements OutboxStoreInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tableName = 'burrow_outbox'
    ) {
    }

    public function enqueue(string $eventKey, array $payload): OutboxRecord
    {
        $id = bin2hex(random_bytes(16));
        $now = $this->formatTimestamp($this->utcNow());
        $payloadJson = $this->encodePayload($payload);

        $sql = sprintf(
            'INSERT INTO %s (id, event_key, status, attempt_count, payload, last_error, created_at, updated_at, next_attempt_at, sent_at)
             VALUES (:id, :event_key, :status, :attempt_count, :payload, :last_error, :created_at, :updated_at, :next_attempt_at, :sent_at)',
            $this->tableName
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':id' => $id,
            ':event_key' => $eventKey,
            ':status' => OutboxStatus::PENDING,
            ':attempt_count' => 0,
            ':payload' => $payloadJson,
            ':last_error' => null,
            ':created_at' => $now,
            ':updated_at' => $now,
            ':next_attempt_at' => null,
            ':sent_at' => null,
        ]);

        return new OutboxRecord(
            id: $id,
            eventKey: $eventKey,
            status: OutboxStatus::PENDING,
            attemptCount: 0,
            payload: $payload,
            lastError: null,
            createdAt: $this->parseTimestamp($now),
            updatedAt: $this->parseTimestamp($now)
        );
    }

    public function pullPending(int $limit = 50): array
    {
        $safeLimit = max(1, $limit);
        $now = $this->formatTimestamp($this->utcNow());
        $sql = sprintf(
            'SELECT id, event_key, status, attempt_count, payload, last_error, created_at, updated_at, next_attempt_at, sent_at
             FROM %s
             WHERE status = :pending
                OR (status = :retrying AND (next_attempt_at IS NULL OR next_attempt_at <= :now))
             ORDER BY created_at ASC
             LIMIT %d',
            $this->tableName,
            $safeLimit
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':pending' => OutboxStatus::PENDING,
            ':retrying' => OutboxStatus::RETRYING,
            ':now' => $now,
        ]);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            $records[] = $this->hydrateRecord($row);
        }

        return $records;
    }

    public function markSent(string $id): void
    {
        $now = $this->formatTimestamp($this->utcNow());
        $sql = sprintf(
            'UPDATE %s
             SET status = :status,
                 attempt_count = attempt_count + 1,
                 last_error = NULL,
                 updated_at = :updated_at,
                 next_attempt_at = NULL,
                 sent_at = :sent_at
             WHERE id = :id',
            $this->tableName
        );

        $this->pdo->prepare($sql)->execute([
            ':status' => OutboxStatus::SENT,
            ':updated_at' => $now,
            ':sent_at' => $now,
            ':id' => $id,
        ]);
    }

    public function markRetrying(string $id, string $error, int $delaySeconds = 0): void
    {
        $now = $this->utcNow();
        $nextAttemptAt = $delaySeconds > 0
            ? $this->formatTimestamp($now->modify(sprintf('+%d seconds', $delaySeconds)))
            : null;

        $sql = sprintf(
            'UPDATE %s
             SET status = :status,
                 attempt_count = attempt_count + 1,
                 last_error = :last_error,
                 updated_at = :updated_at,
                 next_attempt_at = :next_attempt_at,
                 sent_at = NULL
             WHERE id = :id',
            $this->tableName
        );

        $this->pdo->prepare($sql)->execute([
            ':status' => OutboxStatus::RETRYING,
            ':last_error' => $error,
            ':updated_at' => $this->formatTimestamp($now),
            ':next_attempt_at' => $nextAttemptAt,
            ':id' => $id,
        ]);
    }

    public function markFailed(string $id, string $error): void
    {
        $sql = sprintf(
            'UPDATE %s
             SET status = :status,
                 attempt_count = attempt_count + 1,
                 last_error = :last_error,
                 updated_at = :updated_at,
                 next_attempt_at = NULL
             WHERE id = :id',
            $this->tableName
        );

        $this->pdo->prepare($sql)->execute([
            ':status' => OutboxStatus::FAILED,
            ':last_error' => $error,
            ':updated_at' => $this->formatTimestamp($this->utcNow()),
            ':id' => $id,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRecord(array $row): OutboxRecord
    {
        $payloadJson = $row['payload'] ?? null;
        if (!is_string($payloadJson)) {
            throw new RuntimeException('Outbox payload must be a JSON string.');
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Outbox payload JSON must decode to an object.');
        }

        return new OutboxRecord(
            id: (string) $row['id'],
            eventKey: (string) $row['event_key'],
            status: (string) $row['status'],
            attemptCount: (int) $row['attempt_count'],
            payload: $payload,
            lastError: isset($row['last_error']) ? (string) $row['last_error'] : null,
            createdAt: $this->parseTimestamp((string) $row['created_at']),
            updatedAt: $this->parseTimestamp((string) $row['updated_at']),
            nextAttemptAt: isset($row['next_attempt_at']) ? $this->parseTimestamp((string) $row['next_attempt_at']) : null,
            sentAt: isset($row['sent_at']) ? $this->parseTimestamp((string) $row['sent_at']) : null
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        $json = json_encode($payload);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode outbox payload as JSON.');
        }

        return $json;
    }

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function formatTimestamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function parseTimestamp(string $time): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $time, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new RuntimeException('Invalid outbox timestamp value: ' . $time);
        }

        return $parsed;
    }
}
