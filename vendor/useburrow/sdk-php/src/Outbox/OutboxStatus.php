<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

final class OutboxStatus
{
    public const PENDING = 'pending';
    public const RETRYING = 'retrying';
    public const SENT = 'sent';
    public const FAILED = 'failed';
}
