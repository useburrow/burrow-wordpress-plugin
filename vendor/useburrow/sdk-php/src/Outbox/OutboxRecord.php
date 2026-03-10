<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

use DateTimeImmutable;

final readonly class OutboxRecord
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $eventKey,
        public string $status,
        public int $attemptCount,
        public array $payload,
        public ?string $lastError,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?DateTimeImmutable $sentAt = null
    ) {
    }
}
