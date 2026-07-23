<?php
/**
 * Test connection use case.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Application\Connection;

use DateTimeImmutable;
use RuntimeException;
use SalmanButt\Multisite_Content_Sync\Contracts\ConnectionRepository;
use SalmanButt\Multisite_Content_Sync\Contracts\RemoteSiteClient;
use SalmanButt\Multisite_Content_Sync\Domain\Connection\ConnectionStatus;

final readonly class TestConnection {
	public function __construct(
		private ConnectionRepository $connections,
		private RemoteSiteClient $remote_sites,
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function execute( int $id ): array {
		$connection = $this->connections->find( $id );

		if ( null === $connection ) {
			throw new RuntimeException( 'Connection not found.' );
		}

		try {
			$result  = $this->remote_sites->handshake( $connection );
			$updated = $connection->with_health(
				ConnectionStatus::Connected,
				isset( $result['site_uuid'] ) ? (string) $result['site_uuid'] : null,
				isset( $result['plugin_version'] ) ? (string) $result['plugin_version'] : null,
				new DateTimeImmutable( 'now' ),
			);
			$this->connections->save( $updated );

			return $result;
		} catch ( \Throwable $throwable ) {
			$this->connections->save(
				$connection->with_health(
					ConnectionStatus::Failed,
					$connection->remote_site_uuid,
					$connection->remote_plugin_version,
					new DateTimeImmutable( 'now' ),
				)
			);

			throw new RuntimeException( $throwable->getMessage(), 0, $throwable );
		}
	}
}
