<?php

declare(strict_types=1);

namespace Selectwin\Test;

use PHPUnit\Framework\TestCase;
use Selectwin\WebhookEvents;

final class WebhookEventsTest extends TestCase
{
    public function testCatalogIsCompleteAndUnique(): void
    {
        // Tracks the spec's event enum (regen on scripts/sync_core.php).
        $this->assertCount(69, WebhookEvents::ALL);
        $this->assertSame(WebhookEvents::ALL, array_values(array_unique(WebhookEvents::ALL)));
        foreach (['transaction.approved', 'subscription.paused', 'checkout.session.completed', 'webhook.ping'] as $t) {
            $this->assertContains($t, WebhookEvents::ALL);
        }
    }

    public function testIsValid(): void
    {
        $this->assertTrue(WebhookEvents::isValid('transaction.approved'));
        $this->assertTrue(WebhookEvents::isValid('customer.address.deleted'));
        $this->assertFalse(WebhookEvents::isValid('nope.nope'));
    }
}
