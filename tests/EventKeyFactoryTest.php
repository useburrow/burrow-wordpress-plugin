<?php

use BurrowWP\Core\Events\EventKeyFactory;
use PHPUnit\Framework\TestCase;

class EventKeyFactoryTest extends TestCase {
	public function test_creates_deterministic_keys() {
		$factory = new EventKeyFactory();

		$this->assertSame( 'forms:contact:entry_123', $factory->forms_submission_key( 'contact', 'entry_123' ) );
		$this->assertSame( 'ecommerce:order:14577', $factory->ecommerce_order_key( '14577' ) );
		$this->assertSame( 'ecommerce:item:14577:7', $factory->ecommerce_item_key( '14577', '7' ) );
	}
}
