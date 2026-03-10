<?php

use BurrowWP\Core\Events\EnvelopeFactory;
use PHPUnit\Framework\TestCase;

class EventEnvelopeTest extends TestCase {
	public function test_builds_required_fields() {
		$factory = new EnvelopeFactory();
		$payload = $factory->build(
			array(
				'organizationId' => 'org_123',
				'clientId'       => 'cli_123',
				'projectId'      => 'prj_123',
				'projectSourceId'=> 'src_default',
			),
			array(
				'channel'     => 'forms',
				'event'       => 'forms.submission.received',
				'description' => 'Form submission received',
				'properties'  => array( 'submissionId' => 'sub_1' ),
				'tags'        => array( 'formId' => 'contact' ),
			)
		);

		$this->assertSame( 'org_123', $payload['organizationId'] );
		$this->assertSame( 'forms', $payload['channel'] );
		$this->assertSame( 'forms.submission.received', $payload['event'] );
		$this->assertSame( 'wordpress-plugin', $payload['source'] );
		$this->assertArrayHasKey( 'timestamp', $payload );
	}

	public function test_default_icon_is_resolved_from_sdk_mapping() {
		$factory = new EnvelopeFactory();
		$payload = $factory->build(
			array(
				'organizationId' => 'org_123',
				'clientId'       => 'cli_123',
				'projectId'      => 'prj_123',
				'projectSourceId'=> 'src_default',
			),
			array(
				'channel'     => 'forms',
				'event'       => 'forms.submission.received',
				'description' => 'Form submission received',
				'timestamp'   => '2026-03-07T00:00:00.000Z',
			)
		);

		$this->assertSame( 'file-signature', $payload['icon'] );
	}

	public function test_explicit_icon_override_is_preserved() {
		$factory = new EnvelopeFactory();
		$payload = $factory->build(
			array(
				'organizationId' => 'org_123',
				'clientId'       => 'cli_123',
				'projectId'      => 'prj_123',
				'projectSourceId'=> 'src_default',
			),
			array(
				'channel'     => 'forms',
				'event'       => 'forms.submission.received',
				'description' => 'Form submission received',
				'timestamp'   => '2026-03-07T00:00:00.000Z',
				'icon'        => 'star',
			)
		);

		$this->assertSame( 'star', $payload['icon'] );
	}
}
