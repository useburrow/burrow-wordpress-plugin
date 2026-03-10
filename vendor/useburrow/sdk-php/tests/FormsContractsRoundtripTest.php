<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Client\BurrowClient;
use Burrow\Sdk\Contracts\FormsContractCache;
use Burrow\Sdk\Contracts\FormsContractCacheReconciler;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Transport\HttpResponse;
use Burrow\Sdk\Transport\HttpTransportInterface;
use PHPUnit\Framework\TestCase;

final class FormsContractsRoundtripTest extends TestCase
{
    public function testSubmitFormsContractParsesMappingsFromResponse(): void
    {
        $transport = new QueueTransport([
            new HttpResponse(200, [
                'projectSourceId' => 'prj_src_123',
                'contractsVersion' => 'v10',
                'contractMappings' => [[
                    'contractId' => 'ctr_001',
                    'externalFormId' => '42',
                    'formHandle' => 'contact',
                    'formName' => 'Contact Us',
                    'enabled' => true,
                    'updatedAt' => '2026-03-09T10:00:00.000Z',
                    'saved' => true,
                ]],
                'formsContracts' => [[
                    'externalFormId' => '42',
                    'title' => 'Contact Us',
                ]],
            ], '{"ok":true}'),
        ]);
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $response = $client->submitFormsContract(new FormsContractSubmissionRequest([
            'platform' => 'craft',
            'routing' => ['projectId' => 'prj_123'],
            'formsContracts' => [],
        ]));

        $this->assertSame('prj_src_123', $response->projectSourceId);
        $this->assertSame('v10', $response->contractsVersion);
        $this->assertCount(1, $response->contractMappings);
        $this->assertSame('ctr_001', $response->contractMappings[0]->contractId);
        $this->assertSame('42', $response->contractMappings[0]->externalFormId);
        $this->assertSame('contact', $response->contractMappings[0]->formHandle);
    }

    public function testFetchFormsContractsUsesExpectedPayloadAndParsesResponse(): void
    {
        $transport = new QueueTransport([
            new HttpResponse(200, [
                'projectSourceId' => 'prj_src_123',
                'contractsVersion' => 'v10',
                'contractMappings' => [[
                    'contractId' => 'ctr_001',
                    'externalFormId' => '42',
                    'formHandle' => 'contact',
                    'formName' => 'Contact Us',
                    'enabled' => true,
                    'updatedAt' => '2026-03-09T10:00:00.000Z',
                    'saved' => true,
                ]],
                'formsContracts' => [],
            ], '{"ok":true}'),
        ]);
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $response = $client->fetchFormsContracts('prj_123', 'wordpress');

        $this->assertSame('https://api.example.com/api/v1/plugin-onboarding/forms/contracts/fetch', $transport->lastUrl);
        $this->assertSame([
            'platform' => 'wordpress',
            'routing' => ['projectId' => 'prj_123'],
        ], $transport->lastPayload);
        $this->assertSame('v10', $response->contractsVersion);
    }

    public function testReconcileReturnsCurrentCacheWhenVersionMatches(): void
    {
        $serverResponse = $this->buildResponse('v10');
        $localCache = FormsContractCache::fromResponse('prj_123', $serverResponse);

        $result = FormsContractCacheReconciler::reconcile($localCache, $serverResponse, 'prj_123');

        $this->assertTrue($result->isCurrent);
        $this->assertFalse($result->updated);
        $this->assertSame('v10', $result->cache->contractsVersion);
        $this->assertSame('ctr_001', $result->cache->contractIdByFormKey['42|contact']);
    }

    public function testReconcileRefreshesCacheWhenVersionIsStale(): void
    {
        $localCache = FormsContractCache::fromResponse('prj_123', $this->buildResponse('v9'));
        $serverResponse = $this->buildResponse('v10');

        $result = FormsContractCacheReconciler::reconcile($localCache, $serverResponse, 'prj_123');

        $this->assertFalse($result->isCurrent);
        $this->assertTrue($result->updated);
        $this->assertSame('v10', $result->cache->contractsVersion);
        $this->assertSame('ctr_001', $result->cache->contractIdByFormKey['42|contact']);
    }

    private function buildResponse(string $version): \Burrow\Sdk\Contracts\FormsContractsResponse
    {
        return \Burrow\Sdk\Contracts\FormsContractsResponse::fromResponseBody([
            'projectSourceId' => 'prj_src_123',
            'contractsVersion' => $version,
            'contractMappings' => [[
                'contractId' => 'ctr_001',
                'externalFormId' => '42',
                'formHandle' => 'contact',
                'formName' => 'Contact Us',
                'enabled' => true,
                'updatedAt' => '2026-03-09T10:00:00.000Z',
                'saved' => true,
            ]],
            'formsContracts' => [],
        ]);
    }
}

final class QueueTransport implements HttpTransportInterface
{
    /** @var list<HttpResponse> */
    private array $responses;

    /** @var array<string, string> */
    public array $lastHeaders = [];

    /** @var array<string, mixed> */
    public array $lastPayload = [];

    public string $lastUrl = '';

    /**
     * @param list<HttpResponse> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastPayload = $payload;

        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \RuntimeException('No queued response available.');
        }

        return $response;
    }
}
