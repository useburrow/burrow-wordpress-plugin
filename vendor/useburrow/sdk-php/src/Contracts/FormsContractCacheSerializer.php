<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final class FormsContractCacheSerializer
{
    public static function toArray(FormsContractCache $cache): array
    {
        return [
            'projectId' => $cache->projectId,
            'projectSourceId' => $cache->projectSourceId,
            'contractsVersion' => $cache->contractsVersion,
            'contractIdByFormKey' => $cache->contractIdByFormKey,
        ];
    }

    public static function toJson(FormsContractCache $cache): string
    {
        return (string) json_encode(self::toArray($cache), JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): FormsContractCache
    {
        $projectId = isset($payload['projectId']) ? (string) $payload['projectId'] : '';
        if ($projectId === '') {
            throw new \InvalidArgumentException('Forms contract cache payload is missing projectId.');
        }

        $map = [];
        $rawMap = $payload['contractIdByFormKey'] ?? [];
        if (is_array($rawMap)) {
            foreach ($rawMap as $key => $value) {
                if (!is_string($key) || $key === '' || !is_string($value) || $value === '') {
                    continue;
                }

                $map[$key] = $value;
            }
        }

        return new FormsContractCache(
            projectId: $projectId,
            projectSourceId: isset($payload['projectSourceId']) ? (string) $payload['projectSourceId'] : null,
            contractsVersion: isset($payload['contractsVersion']) ? (string) $payload['contractsVersion'] : null,
            contractIdByFormKey: $map
        );
    }

    public static function fromJson(string $json): FormsContractCache
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Forms contract cache JSON payload must decode to an object.');
        }

        return self::fromArray($decoded);
    }
}
