<?php

declare(strict_types=1);

namespace Burrow\Sdk\Transport;

final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMilliseconds = 150,
        public float $multiplier = 2.0,
        public int $maxDelayMilliseconds = 2_000
    ) {
    }

    public function shouldRetryTransportFailure(int $attemptNumber): bool
    {
        return $attemptNumber < $this->maxAttempts;
    }

    public function shouldRetryStatus(int $status, int $attemptNumber): bool
    {
        return $status >= 500 && $status <= 599 && $attemptNumber < $this->maxAttempts;
    }

    public function delayMillisecondsForAttempt(int $attemptNumber): int
    {
        if ($attemptNumber <= 1) {
            return min($this->baseDelayMilliseconds, $this->maxDelayMilliseconds);
        }

        $delay = (int) round($this->baseDelayMilliseconds * ($this->multiplier ** ($attemptNumber - 1)));
        return min($delay, $this->maxDelayMilliseconds);
    }
}
