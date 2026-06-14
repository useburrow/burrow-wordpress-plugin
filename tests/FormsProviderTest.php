<?php

use BurrowWP\Providers\Forms\FluentFormsProvider;
use BurrowWP\Providers\Forms\NinjaFormsProvider;
use PHPUnit\Framework\TestCase;

class FormsProviderTest extends TestCase {
	public function test_ninja_forms_provider_reads_form_id_from_top_level_payload() {
		$provider = new NinjaFormsProvider();
		$result   = $provider->normalize_submission(
			array(
				'form_id'  => 42,
				'settings' => array( 'title' => 'Contact Us' ),
				'fields'   => array(
					array( 'id' => '1', 'value' => 'Jane' ),
					array( 'id' => '2', 'value' => 'jane@example.com' ),
				),
				'actions'  => array(
					'save' => array( 'sub_id' => '991' ),
				),
			)
		);

		$this->assertSame( 'ninja-forms', $result['provider'] );
		$this->assertSame( '42', $result['formId'] );
		$this->assertSame( 'Contact Us', $result['formName'] );
		$this->assertSame( '991', $result['submissionId'] );
		$this->assertSame( 'Jane', $result['rawValues']['1'] );
		$this->assertSame( 'jane@example.com', $result['rawValues']['2'] );
	}

	public function test_fluent_forms_provider_reads_form_metadata_and_values() {
		$provider = new FluentFormsProvider();
		$result   = $provider->normalize_submission(
			array(
				'entry' => array( 'id' => 55 ),
				'form'  => array(
					'id'    => 7,
					'title' => 'Newsletter',
				),
				'data'  => array(
					'email' => 'user@example.com',
				),
			)
		);

		$this->assertSame( 'fluent-forms', $result['provider'] );
		$this->assertSame( '7', $result['formId'] );
		$this->assertSame( 'Newsletter', $result['formName'] );
		$this->assertSame( '55', $result['submissionId'] );
		$this->assertSame( 'user@example.com', $result['rawValues']['email'] );
	}
}
