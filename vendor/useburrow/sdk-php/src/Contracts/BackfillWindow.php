<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class BackfillWindow
{
    public function __construct(
        public string $windowStart,
        public ?string $cursor = null,
        public ?string $windowEnd = null,
        public ?string $source = null
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $payload = [
            'windowStart' => $this->windowStart,
        ];

        if ($this->cursor !== null && $this->cursor !== '') {
            $payload['cursor'] = $this->cursor;
        }
        if ($this->windowEnd !== null && $this->windowEnd !== '') {
            $payload['windowEnd'] = $this->windowEnd;
        }
        if ($this->source !== null && $this->source !== '') {
            $payload['source'] = $this->source;
        }

        return $payload;
    }
}
