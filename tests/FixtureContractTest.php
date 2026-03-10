<?php

use PHPUnit\Framework\TestCase;

class FixtureContractTest extends TestCase {
	public function test_contract_fixture_is_valid_json() {
		$path    = __DIR__ . '/fixtures/forms-contracts.request.json';
		$content = file_get_contents( $path );
		$data    = json_decode( (string) $content, true );

		$this->assertIsArray( $data );
		$this->assertSame( 'wordpress', $data['platform'] );
		$this->assertNotEmpty( $data['formsContracts'] );
	}
}
