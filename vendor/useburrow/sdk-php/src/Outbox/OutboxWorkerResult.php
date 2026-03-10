<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

final readonly class OutboxWorkerResult
{
    public function __construct(
        public int $processedCount,
        public int $sentCount,
        public int $retryingCount,
        public int $failedCount
    ) {
    }
}
