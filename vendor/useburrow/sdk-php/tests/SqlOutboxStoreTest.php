<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Outbox\OutboxStatus;
use Burrow\Sdk\Outbox\SqlOutboxStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqlOutboxStoreTest extends TestCase
{
    private PDO $pdo;
    private SqlOutboxStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->store = new SqlOutboxStore($this->pdo);
    }

    public function testEnqueueAndPullPending(): void
    {
        $record = $this->store->enqueue('event_1', ['event' => 'forms.submission.received']);
        $rows = $this->store->pullPending(10);

        $this->assertCount(1, $rows);
        $this->assertSame($record->id, $rows[0]->id);
        $this->assertSame(OutboxStatus::PENDING, $rows[0]->status);
        $this->assertSame(0, $rows[0]->attemptCount);
    }

    public function testStatusTransitionsAndAttemptCounts(): void
    {
        $record = $this->store->enqueue('event_1', ['event' => 'forms.submission.received']);

        $this->store->markRetrying($record->id, 'temporary failure', 60);
        $retrying = $this->fetchRow($record->id);
        $this->assertSame(OutboxStatus::RETRYING, $retrying['status']);
        $this->assertSame(1, (int) $retrying['attempt_count']);
        $this->assertSame('temporary failure', $retrying['last_error']);
        $this->assertNotNull($retrying['next_attempt_at']);

        $this->store->markFailed($record->id, 'permanent failure');
        $failed = $this->fetchRow($record->id);
        $this->assertSame(OutboxStatus::FAILED, $failed['status']);
        $this->assertSame(2, (int) $failed['attempt_count']);
        $this->assertSame('permanent failure', $failed['last_error']);

        $this->store->markSent($record->id);
        $sent = $this->fetchRow($record->id);
        $this->assertSame(OutboxStatus::SENT, $sent['status']);
        $this->assertSame(3, (int) $sent['attempt_count']);
        $this->assertNull($sent['last_error']);
        $this->assertNotNull($sent['sent_at']);
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE burrow_outbox (
                id TEXT PRIMARY KEY,
                event_key TEXT NOT NULL,
                status TEXT NOT NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                payload TEXT NOT NULL,
                last_error TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                next_attempt_at TEXT NULL,
                sent_at TEXT NULL
            )'
        );

        $this->pdo->exec('CREATE INDEX idx_burrow_outbox_status_next_attempt ON burrow_outbox (status, next_attempt_at)');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM burrow_outbox WHERE id = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
