<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class FormsContractMapping
{
    public function __construct(
        public string $contractId,
        public ?string $externalFormId,
        public ?string $formHandle,
        public ?string $formName,
        public bool $enabled,
        public ?string $updatedAt,
        public bool $saved
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            contractId: (string) ($row['contractId'] ?? ''),
            externalFormId: isset($row['externalFormId']) ? (string) $row['externalFormId'] : null,
            formHandle: isset($row['formHandle']) ? (string) $row['formHandle'] : null,
            formName: isset($row['formName']) ? (string) $row['formName'] : null,
            enabled: (bool) ($row['enabled'] ?? false),
            updatedAt: isset($row['updatedAt']) ? (string) $row['updatedAt'] : null,
            saved: (bool) ($row['saved'] ?? false)
        );
    }
}
