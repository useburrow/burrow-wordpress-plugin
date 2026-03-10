<?php

use BurrowWP\Core\Events\ContractFieldMapper;
use PHPUnit\Framework\TestCase;

class ContractFieldMapperTest extends TestCase {
	public function test_maps_only_contract_approved_fields() {
		$mapper = new ContractFieldMapper();
		$raw    = array(
			'3'      => 'SEO',
			'5'      => '5k-10k',
			'secret' => 'do-not-send',
		);
		$mapped  = $mapper->map(
			$raw,
			array(
				array(
					'externalFieldId' => '3',
					'canonicalKey'    => 'serviceInterest',
					'target'          => 'tags',
				),
				array(
					'externalFieldId' => '5',
					'canonicalKey'    => 'budget',
					'target'          => 'tags',
				),
			)
		);

		$this->assertSame( 'SEO', $mapped['tags']['serviceInterest'] );
		$this->assertSame( '5k-10k', $mapped['tags']['budget'] );
		$this->assertArrayNotHasKey( 'secret', $mapped['tags'] );
		$this->assertSame( array(), $mapped['properties'] );
	}
}
