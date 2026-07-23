<?php
/**
 * Queue retry policy.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Application\Sync;

use DateTimeImmutable;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\RemoteSyncException;

final readonly class RetryPolicy {
	public function __construct(
		private int $maximum_attempts = 5,
		private int $base_delay_seconds = 60,
		private int $maximum_delay_seconds = 3600,
	) {}

	public function can_retry( int $attempts, \Throwable $error ): bool {
		if ( $attempts >= $this->maximum_attempts ) {
			return false;
		}

		if ( ! $error instanceof RemoteSyncException ) {
			return true;
		}

		return 0 === $error->status_code
			|| 408 === $error->status_code
			|| 429 === $error->status_code
			|| $error->status_code >= 500;
	}

	public function next_available_at( int $attempts, DateTimeImmutable $now ): DateTimeImmutable {
		$exponent = max( 0, $attempts - 1 );
		$delay    = min( $this->maximum_delay_seconds, $this->base_delay_seconds * ( 2 ** $exponent ) );

		return $now->modify( sprintf( '+%d seconds', $delay ) );
	}
}
