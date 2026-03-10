<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class FormsContractSubmissionRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public array $payload)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
