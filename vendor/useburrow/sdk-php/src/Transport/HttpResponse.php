<?php

declare(strict_types=1);

namespace Burrow\Sdk\Transport;

final readonly class HttpResponse
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public ?array $body,
        public string $raw,
        public array $headers = []
    ) {
    }

    public function isAccepted(): bool
    {
        return $this->status === 200 || $this->status === 207;
    }
}
