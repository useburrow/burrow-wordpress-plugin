<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class FormsContractCacheReconcileResult
{
    public function __construct(
        public FormsContractCache $cache,
        public bool $isCurrent,
        public bool $updated
    ) {
    }
}
