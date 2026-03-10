<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

interface OutboxStoreInterface
{
    /**
     * @param array<string,mixed> $payload
     */
    public function enqueue(string $eventKey, array $payload): OutboxRecord;

    /**
     * @return list<OutboxRecord>
     */
    public function pullPending(int $limit = 50): array;

    public function markSent(string $id): void;

    public function markRetrying(string $id, string $error, int $delaySeconds = 0): void;

    public function markFailed(string $id, string $error): void;
}
