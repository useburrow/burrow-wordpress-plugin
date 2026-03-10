<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

final readonly class BackfillOptions
{
    public function __construct(
        public int $batchSize = 100,
        public int $concurrency = 4,
        public int $maxAttempts = 3,
        public int $baseDelayMilliseconds = 200,
        public int $maxDelayMilliseconds = 2_000
    ) {
    }
}
