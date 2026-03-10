<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final class FormsContractCacheReconciler
{
    public static function reconcile(
        ?FormsContractCache $localCache,
        FormsContractsResponse $serverResponse,
        string $projectId
    ): FormsContractCacheReconcileResult {
        $serverCache = FormsContractCache::fromResponse($projectId, $serverResponse);

        if ($localCache === null) {
            return new FormsContractCacheReconcileResult(
                cache: $serverCache,
                isCurrent: false,
                updated: true
            );
        }

        $isCurrent = $localCache->projectId === $serverCache->projectId
            && $localCache->projectSourceId === $serverCache->projectSourceId
            && $localCache->contractsVersion !== null
            && $localCache->contractsVersion === $serverCache->contractsVersion;

        if ($isCurrent) {
            return new FormsContractCacheReconcileResult(
                cache: $localCache,
                isCurrent: true,
                updated: false
            );
        }

        return new FormsContractCacheReconcileResult(
            cache: $serverCache,
            isCurrent: false,
            updated: true
        );
    }
}
