<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

interface FormsContractCacheRepositoryInterface
{
    public function load(string $projectId): ?FormsContractCache;

    public function save(FormsContractCache $cache): void;

    public function delete(string $projectId): void;
}
