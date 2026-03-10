<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class FormsContractsFetchRequest
{
    public function __construct(
        public string $platform,
        public string $projectId
    ) {
    }

    /**
     * @return array{
     *   platform:string,
     *   routing:array{projectId:string}
     * }
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'routing' => [
                'projectId' => $this->projectId,
            ],
        ];
    }
}
