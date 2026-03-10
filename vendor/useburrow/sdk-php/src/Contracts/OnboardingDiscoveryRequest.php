<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class OnboardingDiscoveryRequest
{
    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $capabilities
     */
    public function __construct(
        public array $site,
        public array $capabilities
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'site' => $this->site,
            'capabilities' => $this->capabilities,
        ];
    }
}
