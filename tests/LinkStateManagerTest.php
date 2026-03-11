<?php

use BurrowWP\Core\Onboarding\LinkStateManager;
use PHPUnit\Framework\TestCase;

class LinkStateManagerTest extends TestCase {
	public function test_link_response_persists_ingestion_key_and_deep_link_fields() {
		$settings = array(
			'routing' => array(
				'organizationId' => '',
				'clientId'       => '',
				'projectId'      => '',
				'projectSourceId'=> '',
				'sourceIds'      => array(),
			),
		);
		$body = array(
			'sdkState' => array(
				'ingestionKey'         => 'ing_prj_key',
				'projectId'            => 'prj_1',
				'clientId'             => 'cli_1',
				'formsProjectSourceId' => 'src_forms_1',
			),
			'routing' => array(
				'organizationId' => 'org_1',
				'sourceIds'      => array( 'forms' => 'src_forms_1' ),
			),
			'ingestionKey' => array(
				'key'       => 'ing_prj_key',
				'projectId' => 'prj_1',
				'keyPrefix' => 'ing_prj',
			),
			'project' => array(
				'burrowProjectPath' => '/clients/cli_1/projects/prj_1',
				'burrowProjectUrl'  => 'https://app.useburrow.com/clients/cli_1/projects/prj_1',
			),
		);

		$updated = LinkStateManager::apply_link_response( $settings, $body );
		$this->assertSame( 'org_1', $updated['routing']['organizationId'] );
		$this->assertSame( 'prj_1', $updated['routing']['projectId'] );
		$this->assertSame( 'ing_prj_key', $updated['ingestion_key']['key'] );
		$this->assertSame( '/clients/cli_1/projects/prj_1', $updated['burrow_project']['path'] );
		$this->assertSame( 'https://app.useburrow.com/clients/cli_1/projects/prj_1', $updated['burrow_project']['url'] );
		$this->assertSame( 'src_forms_1', $updated['routing']['sourceIds']['forms'] );
	}

	public function test_link_response_persists_project_project_id_from_sdk_state() {
		$settings = array(
			'routing' => array(
				'organizationId' => '',
				'clientId'       => '',
				'projectId'      => '',
				'projectSourceId'=> '',
				'sourceIds'      => array(),
			),
		);
		$updated = LinkStateManager::apply_link_response(
			$settings,
			array(
				'sdkState' => array(
					'ingestionKey' => 'ing_key',
					'projectId'    => 'prj_from_sdk',
				),
				'project' => array(
					'burrowProjectPath' => '/clients/c/projects/prj_from_sdk',
					'burrowProjectUrl'  => 'https://app.useburrow.com/clients/c/projects/prj_from_sdk',
				),
			)
		);

		$this->assertSame( 'prj_from_sdk', $updated['routing']['projectId'] );
		$this->assertSame( 'ing_key', $updated['ingestion_key']['key'] );
	}

	public function test_project_deep_link_url_returns_blank_for_missing_or_invalid_url() {
		$this->assertSame( '', LinkStateManager::project_url_from_settings( array() ) );
		$this->assertSame(
			'',
			LinkStateManager::project_url_from_settings(
				array(
					'burrow_project' => array(
						'url' => 'not a url',
					),
				)
			)
		);
		$this->assertSame(
			'https://app.useburrow.com/projects/prj_1',
			LinkStateManager::project_url_from_settings(
				array(
					'burrow_project' => array(
						'url' => 'https://app.useburrow.com/projects/prj_1',
					),
				)
			)
		);
	}

	public function test_project_deep_link_url_prefers_direct_url_over_path_construction() {
		$this->assertSame(
			'https://app.useburrow.com/clients/cli_1/projects/prj_1',
			LinkStateManager::project_url_from_settings(
				array(
					'base_url'       => 'https://api.useburrow.com',
					'burrow_project' => array(
						'path' => '/clients/cli_old/projects/prj_old',
						'url'  => 'https://app.useburrow.com/clients/cli_1/projects/prj_1',
					),
				)
			)
		);
	}

	public function test_project_deep_link_falls_back_to_path_when_url_empty() {
		$this->assertSame(
			'https://app.useburrow.com/clients/cli_1/projects/prj_1',
			LinkStateManager::project_url_from_settings(
				array(
					'base_url'       => 'https://api.useburrow.com',
					'burrow_project' => array(
						'path' => '/clients/cli_1/projects/prj_1',
						'url'  => '',
					),
				)
			)
		);
	}
}
