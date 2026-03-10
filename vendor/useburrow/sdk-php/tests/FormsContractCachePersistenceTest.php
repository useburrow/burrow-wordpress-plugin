<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Contracts\FormsContractCache;
use Burrow\Sdk\Contracts\FormsContractCacheSerializer;
use Burrow\Sdk\Contracts\InMemoryFormsContractCacheRepository;
use PHPUnit\Framework\TestCase;

final class FormsContractCachePersistenceTest extends TestCase
{
    public function testSerializerRoundTripsCache(): void
    {
        $cache = new FormsContractCache(
            projectId: 'prj_123',
            projectSourceId: 'prj_src_123',
            contractsVersion: 'v10',
            contractIdByFormKey: [
                '42|contact' => 'ctr_001',
                '84|newsletter' => 'ctr_002',
            ]
        );

        $json = FormsContractCacheSerializer::toJson($cache);
        $decoded = FormsContractCacheSerializer::fromJson($json);

        $this->assertSame('prj_123', $decoded->projectId);
        $this->assertSame('prj_src_123', $decoded->projectSourceId);
        $this->assertSame('v10', $decoded->contractsVersion);
        $this->assertSame('ctr_001', $decoded->contractIdByFormKey['42|contact']);
    }

    public function testInMemoryRepositoryLoadSaveDelete(): void
    {
        $repository = new InMemoryFormsContractCacheRepository();
        $cache = new FormsContractCache(
            projectId: 'prj_123',
            projectSourceId: 'prj_src_123',
            contractsVersion: 'v10',
            contractIdByFormKey: ['42|contact' => 'ctr_001']
        );

        $repository->save($cache);
        $loaded = $repository->load('prj_123');
        $this->assertNotNull($loaded);
        $this->assertSame('ctr_001', $loaded->contractIdByFormKey['42|contact']);

        $repository->delete('prj_123');
        $this->assertNull($repository->load('prj_123'));
    }
}
