<?php
/**
 * Exponential retry policy.
 *
 * @package Burrow
 */

namespace BurrowWP\Core\Outbox;

class RetryPolicy {
	/**
	 * Backoff schedule in seconds.
	 *
	 * @var int[]
	 */
	private $schedule = array( 60, 300, 1800, 7200, 43200, 86400 );

	/**
	 * Compute next attempt timestamp.
	 *
	 * @param int $attempt Attempt number (1-indexed).
	 * @return string
	 */
	public function next_attempt_utc( $attempt ) {
		$attempt = max( 1, (int) $attempt );
		$idx     = min( $attempt - 1, count( $this->schedule ) - 1 );
		$delta   = $this->schedule[ $idx ];
		return gmdate( 'Y-m-d H:i:s', time() + $delta );
	}
}
