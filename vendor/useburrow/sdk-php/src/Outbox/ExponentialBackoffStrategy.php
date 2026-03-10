<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

final readonly class ExponentialBackoffStrategy implements BackoffStrategyInterface
{
    public function __construct(
        private int $baseDelaySeconds = 2,
        private float $multiplier = 2.0,
        private int $maxDelaySeconds = 300
    ) {
    }

    public function delaySecondsForAttempt(int $attemptNumber): int
    {
        if ($attemptNumber <= 0) {
            return 0;
        }

        $delay = (int) round($this->baseDelaySeconds * ($this->multiplier ** ($attemptNumber - 1)));
        return min($delay, $this->maxDelaySeconds);
    }
}
