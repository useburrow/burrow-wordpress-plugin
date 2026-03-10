<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client\Exception;

use Burrow\Sdk\Transport\HttpResponse;
use RuntimeException;

final class UnexpectedResponseStatusException extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly HttpResponse $response
    ) {
        parent::__construct(sprintf(
            'Burrow endpoint %s returned status %d; expected 200 or 207.',
            $path,
            $response->status
        ));
    }

    public function isRetryable(): bool
    {
        return $this->response->status === 429
            || ($this->response->status >= 500 && $this->response->status <= 599);
    }
}
