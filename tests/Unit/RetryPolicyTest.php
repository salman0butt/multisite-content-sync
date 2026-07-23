<?php
/**
 * Retry policy tests.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SalmanButt\Multisite_Content_Sync\Application\Sync\RetryPolicy;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\RemoteSyncException;

final class RetryPolicyTest extends TestCase {
	public function test_transient_errors_are_retried(): void {
		$policy = new RetryPolicy();

		self::assertTrue( $policy->can_retry( 1, new RemoteSyncException( 'Busy', 503 ) ) );
		self::assertTrue( $policy->can_retry( 1, new RemoteSyncException( 'Limited', 429 ) ) );
		self::assertTrue( $policy->can_retry( 1, new RuntimeException( 'Transport' ) ) );
	}

	public function test_permanent_errors_and_exhausted_jobs_are_not_retried(): void {
		$policy = new RetryPolicy();

		self::assertFalse( $policy->can_retry( 1, new RemoteSyncException( 'Bad request', 400 ) ) );
		self::assertFalse( $policy->can_retry( 5, new RemoteSyncException( 'Busy', 503 ) ) );
	}

	public function test_backoff_is_exponential_and_capped(): void {
		$policy = new RetryPolicy( 10, 60, 300 );
		$now    = new DateTimeImmutable( '2026-07-23 00:00:00 UTC' );

		self::assertSame( '2026-07-23 00:01:00', $policy->next_available_at( 1, $now )->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '2026-07-23 00:04:00', $policy->next_available_at( 3, $now )->format( 'Y-m-d H:i:s' ) );
		self::assertSame( '2026-07-23 00:05:00', $policy->next_available_at( 8, $now )->format( 'Y-m-d H:i:s' ) );
	}
}
