<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class FormsContractCache
{
    /**
     * @param array<string,string> $contractIdByFormKey
     */
    public function __construct(
        public string $projectId,
        public ?string $projectSourceId,
        public ?string $contractsVersion,
        public array $contractIdByFormKey
    ) {
    }

    public static function fromResponse(string $projectId, FormsContractsResponse $response): self
    {
        $map = [];
        foreach ($response->contractMappings as $mapping) {
            $key = self::formKey($mapping->externalFormId, $mapping->formHandle);
            if ($key === null) {
                continue;
            }

            $map[$key] = $mapping->contractId;
        }

        return new self(
            projectId: $projectId,
            projectSourceId: $response->projectSourceId,
            contractsVersion: $response->contractsVersion,
            contractIdByFormKey: $map
        );
    }

    public static function formKey(?string $externalFormId, ?string $formHandle): ?string
    {
        $left = trim((string) ($externalFormId ?? ''));
        $right = trim((string) ($formHandle ?? ''));
        if ($left === '' && $right === '') {
            return null;
        }

        return $left . '|' . $right;
    }
}
