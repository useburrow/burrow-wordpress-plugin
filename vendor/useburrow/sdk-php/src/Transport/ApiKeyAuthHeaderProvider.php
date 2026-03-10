<?php

declare(strict_types=1);

namespace Burrow\Sdk\Transport;

final class ApiKeyAuthHeaderProvider
{
    /**
     * @return array<string, string>
     */
    public static function fromApiKey(string $apiKey): array
    {
        return ['x-api-key' => trim($apiKey)];
    }
}
