<?php

declare(strict_types=1);

namespace Burrow\Sdk\Tests;

use Burrow\Sdk\Events\EventIconResolver;
use PHPUnit\Framework\TestCase;

final class EventIconResolverTest extends TestCase
{
    public function testReturnsCanonicalIconForKnownEvents(): void
    {
        $this->assertSame('file-signature', EventIconResolver::resolveIconForEvent('forms', 'forms.submission.received'));
        $this->assertSame('heart-pulse', EventIconResolver::resolveIconForEvent('system', 'system.heartbeat.ping'));
        $this->assertSame('shopping-cart', EventIconResolver::resolveIconForEvent('ecommerce', 'ecommerce.order.placed'));
    }

    public function testFallsBackToChannelDefaultForUnknownEvent(): void
    {
        $this->assertSame('chart-column', EventIconResolver::resolveIconForEvent('analytics', 'analytics.unknown.event'));
    }

    public function testReturnsNullForUnknownChannelAndEvent(): void
    {
        $this->assertNull(EventIconResolver::resolveIconForEvent('unknown-channel', 'unknown.event'));
    }
}
