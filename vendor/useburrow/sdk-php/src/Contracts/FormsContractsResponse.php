<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class FormsContractsResponse
{
    /**
     * @param list<FormsContractMapping> $contractMappings
     * @param list<array<string,mixed>> $formsContracts
     */
    public function __construct(
        public ?string $projectSourceId,
        public ?string $contractsVersion,
        public array $contractMappings,
        public array $formsContracts
    ) {
    }

    /**
     * @param array<string,mixed>|null $body
     */
    public static function fromResponseBody(?array $body): self
    {
        $payload = $body ?? [];
        $mappingsRows = is_array($payload['contractMappings'] ?? null)
            ? $payload['contractMappings']
            : [];
        $formsContracts = is_array($payload['formsContracts'] ?? null)
            ? array_values(array_filter($payload['formsContracts'], static fn ($row): bool => is_array($row)))
            : [];

        $mappings = [];
        foreach ($mappingsRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $mapping = FormsContractMapping::fromArray($row);
            if ($mapping->contractId === '') {
                continue;
            }

            $mappings[] = $mapping;
        }

        return new self(
            projectSourceId: isset($payload['projectSourceId']) ? (string) $payload['projectSourceId'] : null,
            contractsVersion: isset($payload['contractsVersion']) ? (string) $payload['contractsVersion'] : null,
            contractMappings: $mappings,
            formsContracts: $formsContracts
        );
    }
}
