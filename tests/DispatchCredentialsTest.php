<?php

use BurrowWP\Core\Auth\DispatchCredentials;
use PHPUnit\Framework\TestCase;

class DispatchCredentialsTest extends TestCase {
	public function test_resolve_dispatch_api_key_prefers_scoped_ingestion_key() {
		$resolved = DispatchCredentials::resolve_dispatch_api_key(
			'brw_live_org_key',
			array( 'key' => 'brw_ingestion_project_key' )
		);
		$this->assertSame( 'brw_ingestion_project_key', $resolved );
	}

	public function test_resolve_dispatch_api_key_falls_back_to_default_key() {
		$resolved = DispatchCredentials::resolve_dispatch_api_key(
			'brw_live_org_key',
			array()
		);
		$this->assertSame( 'brw_live_org_key', $resolved );
	}

	public function test_resolve_dispatch_project_id_prefers_routing_then_ingestion() {
		$this->assertSame(
			'prj_routing',
			DispatchCredentials::resolve_dispatch_project_id(
				'prj_routing',
				array( 'projectId' => 'prj_ingestion' )
			)
		);
		$this->assertSame(
			'prj_ingestion',
			DispatchCredentials::resolve_dispatch_project_id(
				'',
				array( 'projectId' => 'prj_ingestion' )
			)
		);
	}
}
