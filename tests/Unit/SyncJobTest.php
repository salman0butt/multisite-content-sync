<?php
/**
 * Sync job tests.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\SyncJob;

final class SyncJobTest extends TestCase {
	public function test_active_key_is_stable(): void {
		$first  = SyncJob::active_key( 10, 'post', 20, 'upsert' );
		$second = SyncJob::active_key( 10, 'post', 20, 'upsert' );

		self::assertSame( $first, $second );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first );
	}

	public function test_active_key_changes_with_job_identity(): void {
		self::assertNotSame(
			SyncJob::active_key( 10, 'post', 20, 'upsert' ),
			SyncJob::active_key( 11, 'post', 20, 'upsert' ),
		);
		self::assertNotSame(
			SyncJob::active_key( 10, 'post', 20, 'upsert' ),
			SyncJob::active_key( 10, 'page', 20, 'upsert' ),
		);
	}
}
