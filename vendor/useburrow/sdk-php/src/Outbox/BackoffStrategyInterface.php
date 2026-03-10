<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

interface BackoffStrategyInterface
{
    public function delaySecondsForAttempt(int $attemptNumber): int;
}
