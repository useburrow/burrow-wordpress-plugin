<?php

use BurrowWP\Providers\Forms\FluentFormsProvider;
use BurrowWP\Providers\Forms\NinjaFormsProvider;
use BurrowWP\Providers\Forms\SureFormsProvider;
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

	public function test_sureforms_provider_reads_submit_response_payload() {
		$provider = new SureFormsProvider();
		$result   = $provider->normalize_submission(
			array(
				'success'   => true,
				'form_id'   => 12,
				'entry_id'  => 340,
				'to_emails' => array( 'admin@example.com' ),
				'form_name' => 'Contact',
				'message'   => '<p>Thanks!</p>',
				'data'      => array(
					'name'          => 'Jane',
					'email-address' => 'jane@example.com',
				),
			)
		);

		$this->assertSame( 'sure-forms', $result['provider'] );
		$this->assertSame( '12', $result['formId'] );
		$this->assertSame( 'Contact', $result['formName'] );
		$this->assertSame( '340', $result['submissionId'] );
		$this->assertSame( 'jane@example.com', $result['rawValues']['email-address'] );
	}

	public function test_sureforms_provider_generates_submission_id_when_gdpr_omits_entry_id() {
		$provider = new SureFormsProvider();
		$result   = $provider->normalize_submission(
			array(
				'success'   => true,
				'form_id'   => 12,
				'form_name' => 'Contact',
				'data'      => array( 'name' => 'Jane' ),
			)
		);

		$this->assertNotSame( '', $result['submissionId'] );
		$this->assertStringStartsWith( 'srfm_', $result['submissionId'] );
	}
}
