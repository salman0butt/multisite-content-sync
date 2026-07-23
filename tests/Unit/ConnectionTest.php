<?php
/**
 * Connection entity tests.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SalmanButt\Multisite_Content_Sync\Domain\Connection\Connection;
use SalmanButt\Multisite_Content_Sync\Domain\Connection\ConnectionStatus;

final class ConnectionTest extends TestCase {
	public function test_it_returns_new_instances_for_state_changes(): void {
		$connection = new Connection( null, 'Site', 'https://example.com', 'receiver', 'secret' );
		$persisted  = $connection->with_id( 10 );
		$connected  = $persisted->with_health(
			ConnectionStatus::Connected,
			'77f83a80-f877-4d34-9c25-1fd15d1038ce',
			'0.1.0',
			new DateTimeImmutable( '2026-07-23 10:00:00' ),
		);

		self::assertNull( $connection->id );
		self::assertSame( 10, $persisted->id );
		self::assertSame( ConnectionStatus::Connected, $connected->status );
		self::assertSame( '0.1.0', $connected->remote_plugin_version );
	}
}
