<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class OnboardingLinkRequest
{
    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $selection
     */
    public function __construct(
        public array $site,
        public array $selection
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site' => $this->site,
            'selection' => $this->selection,
        ];
    }
}
