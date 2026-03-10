<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Events\EventEnvelopeBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EventEnvelopeBuilderTest extends TestCase
{
    public function testBuildsEnvelopeWithDefaults(): void
    {
        $event = EventEnvelopeBuilder::build([
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'timestamp' => '2026-03-07T00:00:00.000Z',
        ]);

        $this->assertSame('1', $event['schemaVersion']);
        $this->assertFalse($event['isLifecycle']);
        $this->assertSame([], $event['properties']);
        $this->assertSame([], $event['tags']);
        $this->assertNull($event['integrationId']);
        $this->assertNull($event['clientSourceId']);
        $this->assertSame('file-signature', $event['icon']);
        $this->assertNull($event['entityType']);
        $this->assertNull($event['externalEntityId']);
        $this->assertNull($event['externalEventId']);
        $this->assertNull($event['state']);
        $this->assertNull($event['stateChangedAt']);
    }

    public function testThrowsForMissingRequiredField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventEnvelopeBuilder::build([
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
        ]);
    }

    public function testAppliesTypeSafeFallbacksForOptionalFields(): void
    {
        $event = EventEnvelopeBuilder::build([
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'timestamp' => '2026-03-07T00:00:00.000Z',
            'properties' => 'not-an-array',
            'tags' => 'also-not-an-array',
        ]);

        $this->assertSame([], $event['properties']);
        $this->assertSame([], $event['tags']);
        $this->assertNull($event['projectId']);
    }

    public function testAcceptsLifecycleOverrideFields(): void
    {
        $event = EventEnvelopeBuilder::build([
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'channel' => 'system',
            'event' => 'system.lifecycle.updated',
            'timestamp' => '2026-03-07T00:00:00.000Z',
            'isLifecycle' => true,
            'entityType' => 'contract',
            'externalEntityId' => 'form_123',
            'externalEventId' => 'evt_123',
            'state' => 'synced',
            'stateChangedAt' => '2026-03-07T00:00:30.000Z',
        ]);

        $this->assertTrue($event['isLifecycle']);
        $this->assertSame('contract', $event['entityType']);
        $this->assertSame('form_123', $event['externalEntityId']);
        $this->assertSame('evt_123', $event['externalEventId']);
        $this->assertSame('synced', $event['state']);
        $this->assertSame('2026-03-07T00:00:30.000Z', $event['stateChangedAt']);
    }

    public function testPreservesExplicitIconOverride(): void
    {
        $event = EventEnvelopeBuilder::build([
            'organizationId' => 'org_123',
            'clientId' => 'client_123',
            'channel' => 'forms',
            'event' => 'forms.submission.received',
            'timestamp' => '2026-03-07T00:00:00.000Z',
            'icon' => 'star',
        ]);

        $this->assertSame('star', $event['icon']);
    }

    public function testBuildsExpectedEnvelopeFromFixture(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/spec/contracts/event-forms-submission.json';
        $contents = file_get_contents($fixturePath);
        self::assertNotFalse($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        $built = EventEnvelopeBuilder::build($decoded);
        $this->assertSame($decoded['organizationId'], $built['organizationId']);
        $this->assertSame($decoded['clientId'], $built['clientId']);
        $this->assertSame($decoded['channel'], $built['channel']);
        $this->assertSame($decoded['event'], $built['event']);
        $this->assertSame($decoded['timestamp'], $built['timestamp']);
        $this->assertSame('1', $built['schemaVersion']);
        $this->assertFalse($built['isLifecycle']);
        $this->assertSame($decoded['properties'], $built['properties']);
        $this->assertSame($decoded['tags'], $built['tags']);
    }
}
