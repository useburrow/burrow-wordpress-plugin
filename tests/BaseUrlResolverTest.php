<?php

use BurrowWP\Core\Config\BaseUrlResolver;
use PHPUnit\Framework\TestCase;

class BaseUrlResolverTest extends TestCase {
	protected function tearDown(): void {
		putenv( 'BURROW_BASE_URL' );
		unset( $_ENV['BURROW_BASE_URL'], $_SERVER['BURROW_BASE_URL'] );
		parent::tearDown();
	}

	public function test_default_base_url_is_app_host() {
		$this->assertSame( 'https://app.useburrow.com', BaseUrlResolver::DEFAULT_BASE_URL );
		$this->assertSame( 'https://app.useburrow.com', BaseUrlResolver::resolve( null ) );
		$this->assertSame( 'https://app.useburrow.com', BaseUrlResolver::resolve( array() ) );
	}

	public function test_saved_settings_base_url_is_used_when_env_missing() {
		$this->assertSame(
			'https://custom.example.com',
			BaseUrlResolver::resolve( array( 'base_url' => 'https://custom.example.com/' ) )
		);
	}

	public function test_env_override_wins_over_settings() {
		putenv( 'BURROW_BASE_URL=https://env.useburrow.com' );
		$_ENV['BURROW_BASE_URL'] = 'https://env.useburrow.com';
		$this->assertSame(
			'https://env.useburrow.com',
			BaseUrlResolver::resolve( array( 'base_url' => 'https://app.useburrow.com' ) )
		);
	}
}
