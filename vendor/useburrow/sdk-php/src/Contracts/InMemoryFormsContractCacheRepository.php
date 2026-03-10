<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final class InMemoryFormsContractCacheRepository implements FormsContractCacheRepositoryInterface
{
    /** @var array<string, FormsContractCache> */
    private array $records = [];

    public function load(string $projectId): ?FormsContractCache
    {
        return $this->records[$projectId] ?? null;
    }

    public function save(FormsContractCache $cache): void
    {
        $this->records[$cache->projectId] = $cache;
    }

    public function delete(string $projectId): void
    {
        unset($this->records[$projectId]);
    }
}
