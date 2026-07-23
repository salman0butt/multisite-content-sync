<?php
/**
 * Wpdb connection repository.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SalmanButt\Multisite_Content_Sync\Contracts\ConnectionRepository;
use SalmanButt\Multisite_Content_Sync\Domain\Connection\Connection;
use SalmanButt\Multisite_Content_Sync\Domain\Connection\ConnectionInUseException;
use SalmanButt\Multisite_Content_Sync\Domain\Connection\ConnectionStatus;
use wpdb;

final readonly class WpdbConnectionRepository implements ConnectionRepository {
	public function __construct( private wpdb $database ) {}

	public function all(): array {
		$query = $this->database->prepare(
			'SELECT * FROM %i ORDER BY name ASC',
			$this->table(),
		);
		$rows  = $this->database->get_results( $query, ARRAY_A );

		$connections = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$connections[] = $this->hydrate( $row );
			}
		}

		return $connections;
	}

	public function find( int $id ): ?Connection {
		$query = $this->database->prepare(
			'SELECT * FROM %i WHERE id = %d',
			$this->table(),
			$id,
		);
		$row   = $this->database->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function find_by_site_url( string $site_url ): ?Connection {
		$query = $this->database->prepare(
			'SELECT * FROM %i WHERE site_url = %s LIMIT 1',
			$this->table(),
			$site_url,
		);
		$row   = $this->database->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function save( Connection $connection ): Connection {
		$data = array(
			'name'                  => $connection->name,
			'site_url'              => $connection->site_url,
			'username'              => $connection->username,
			'encrypted_credential'  => $connection->encrypted_credential,
			'status'                => $connection->status->value,
			'remote_site_uuid'      => $connection->remote_site_uuid,
			'remote_plugin_version' => $connection->remote_plugin_version,
			'last_checked_at'       => $connection->last_checked_at?->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'updated_at'            => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( null === $connection->id ) {
			$data['created_at'] = current_time( 'mysql', true );
			$formats[]          = '%s';
			$result             = $this->database->insert( $this->table(), $data, $formats );

			if ( false === $result || (int) $this->database->insert_id <= 0 ) {
				throw new RuntimeException( 'Unable to save the connection.' );
			}

			return $connection->with_id( (int) $this->database->insert_id );
		}

		$result = $this->database->update(
			$this->table(),
			$data,
			array( 'id' => $connection->id ),
			$formats,
			array( '%d' ),
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to update the connection.' );
		}

		return $connection;
	}

	public function delete( int $id ): bool {
		$processing_jobs = (int) $this->database->get_var(
			$this->database->prepare(
				'SELECT COUNT(*) FROM %i WHERE connection_id = %d AND status = %s',
				$this->database->prefix . 'mcs_jobs',
				$id,
				'processing',
			)
		);

		if ( $processing_jobs > 0 ) {
			throw new ConnectionInUseException( 'The connection has synchronization jobs in progress.' );
		}

		if ( false === $this->database->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'Unable to begin the connection deletion transaction.' );
		}

		$deleted = $this->database->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' ),
		);

		if ( 1 !== $deleted ) {
			$this->database->query( 'ROLLBACK' );
			return false;
		}

		$jobs_deleted = $this->database->delete(
			$this->database->prefix . 'mcs_jobs',
			array( 'connection_id' => $id ),
			array( '%d' ),
		);
		$mappings_deleted = $this->database->delete(
			$this->database->prefix . 'mcs_mappings',
			array( 'connection_id' => $id ),
			array( '%d' ),
		);

		if ( false === $jobs_deleted || false === $mappings_deleted ) {
			$this->database->query( 'ROLLBACK' );
			return false;
		}

		if ( false === $this->database->query( 'COMMIT' ) ) {
			$this->database->query( 'ROLLBACK' );
			throw new RuntimeException( 'Unable to commit the connection deletion transaction.' );
		}

		return true;
	}

	private function table(): string {
		return $this->database->prefix . 'mcs_connections';
	}

	/**
	 * Hydrate a connection from a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): Connection {
		$timezone = new DateTimeZone( 'UTC' );

		return new Connection(
			(int) $row['id'],
			(string) $row['name'],
			(string) $row['site_url'],
			(string) $row['username'],
			(string) $row['encrypted_credential'],
			ConnectionStatus::tryFrom( (string) $row['status'] ) ?? ConnectionStatus::Pending,
			! empty( $row['remote_site_uuid'] ) ? (string) $row['remote_site_uuid'] : null,
			! empty( $row['remote_plugin_version'] ) ? (string) $row['remote_plugin_version'] : null,
			! empty( $row['last_checked_at'] ) ? new DateTimeImmutable( (string) $row['last_checked_at'], $timezone ) : null,
		);
	}
}
