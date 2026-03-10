<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContractFixturesTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_EVENT_KEYS = [
        'organizationId',
        'clientId',
        'projectId',
        'projectSourceId',
        'channel',
        'event',
        'timestamp',
        'source',
        'description',
        'schemaVersion',
        'properties',
        'tags',
    ];

    public function testFormsContractSubmissionFixtureCanBeSubmitted(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/spec/contracts/forms-contracts.request.json';
        $contents = file_get_contents($fixturePath);
        self::assertNotFalse($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        $request = new FormsContractSubmissionRequest($decoded);
        $this->assertSame($decoded, $request->toArray());
    }

    #[DataProvider('canonicalEventFixtureProvider')]
    public function testCanonicalEventFixturesHaveRequiredEnvelopeShape(string $fixtureName, string $expectedEvent): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/spec/contracts/' . $fixtureName;
        $contents = file_get_contents($fixturePath);
        self::assertNotFalse($contents, sprintf('Missing fixture %s', $fixtureName));

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        foreach (self::REQUIRED_EVENT_KEYS as $key) {
            $this->assertArrayHasKey($key, $decoded, sprintf('Fixture %s is missing key %s', $fixtureName, $key));
        }

        $this->assertSame($expectedEvent, $decoded['event']);
        $this->assertSame('1', $decoded['schemaVersion']);
        $this->assertIsArray($decoded['properties']);
        $this->assertIsArray($decoded['tags']);
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public static function canonicalEventFixtureProvider(): array
    {
        return [
            ['event-system-stack-snapshot.json', 'system.stack.snapshot'],
            ['event-system-heartbeat-ping.json', 'system.heartbeat.ping'],
            ['event-ecommerce-order-placed.json', 'ecommerce.order.placed'],
            ['event-ecommerce-item-purchased.json', 'ecommerce.item.purchased'],
        ];
    }
}
