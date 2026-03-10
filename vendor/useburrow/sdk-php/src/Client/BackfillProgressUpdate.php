<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

final readonly class BackfillProgressUpdate
{
    public function __construct(
        public string $status,
        public int $queuedCount,
        public int $runningCount,
        public int $completedCount,
        public int $failedCount,
        public int $acceptedCount,
        public int $rejectedCount,
        public ?string $latestCursor
    ) {
    }
}
