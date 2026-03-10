<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Client\BurrowClient;
use Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException;
use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\BackfillWindow;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Transport\HttpResponse;
use Burrow\Sdk\Transport\HttpTransportInterface;
use PHPUnit\Framework\TestCase;

final class BurrowClientTest extends TestCase
{
    public function testDiscoverUsesExpectedPathHeadersAndPayload(): void
    {
        $transport = new RecordingTransport(new HttpResponse(200, ['ok' => true], '{"ok":true}'));
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $request = new OnboardingDiscoveryRequest(
            site: ['url' => 'https://site.test'],
            capabilities: ['forms' => ['freeform']]
        );

        $response = $client->discover($request);

        $this->assertSame(200, $response->status);
        $this->assertSame('https://api.example.com/api/v1/plugin-onboarding/discover', $transport->lastUrl);
        $this->assertSame(['x-api-key' => 'secret_key'], $transport->lastHeaders);
        $this->assertSame($request->toArray(), $transport->lastPayload);
    }

    public function testSupportsAllEndpointMethods(): void
    {
        $transport = new RecordingTransport(new HttpResponse(207, ['partial' => true], '{"partial":true}'));
        $client = new BurrowClient('https://api.example.com/', 'secret_key', $transport);

        $client->link(new OnboardingLinkRequest(
            site: ['url' => 'https://site.test'],
            selection: ['organizationId' => 'org_123']
        ));
        $this->assertSame('https://api.example.com/api/v1/plugin-onboarding/link', $transport->lastUrl);

        $client->submitFormsContract(new FormsContractSubmissionRequest(['formsContracts' => []]));
        $this->assertSame('https://api.example.com/api/v1/plugin-onboarding/forms/contracts', $transport->lastUrl);

        $client->fetchFormsContracts('prj_123', 'craft');
        $this->assertSame('https://api.example.com/api/v1/plugin-onboarding/forms/contracts/fetch', $transport->lastUrl);

        $client->publishEvent(['event' => 'forms.submission.received']);
        $this->assertSame('https://api.example.com/api/v1/events', $transport->lastUrl);

        $client->backfillEvents(new BackfillEventsRequest(
            events: [[
                'event' => 'forms.submission.received',
                'timestamp' => '2026-03-01T12:00:00.000Z',
            ]],
            backfill: new BackfillWindow(windowStart: '2026-03-01T00:00:00.000Z')
        ));
        $this->assertSame('https://api.example.com/api/v1/plugin-backfill/events', $transport->lastUrl);
    }

    public function testThrowsOnUnexpectedStatusCode(): void
    {
        $transport = new RecordingTransport(new HttpResponse(400, ['error' => 'bad request'], '{"error":"bad request"}'));
        $client = new BurrowClient('https://api.example.com', 'secret_key', $transport);

        $this->expectException(UnexpectedResponseStatusException::class);
        $client->publishEvent(['event' => 'forms.submission.received']);
    }
}

final class RecordingTransport implements HttpTransportInterface
{
    /** @var array<string, string> */
    public array $lastHeaders = [];

    /** @var array<string, mixed> */
    public array $lastPayload = [];

    public string $lastUrl = '';

    public function __construct(private readonly HttpResponse $response)
    {
    }

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastPayload = $payload;

        return $this->response;
    }
}
