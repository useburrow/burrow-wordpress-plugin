<?php

declare(strict_types=1);

namespace Burrow\Sdk\Transport;

interface HttpTransportInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     */
    public function post(string $url, array $headers, array $payload): HttpResponse;
}
