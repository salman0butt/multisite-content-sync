<?php
/**
 * Wpdb content mapping repository.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SalmanButt\Multisite_Content_Sync\Contracts\MappingRepository;
use SalmanButt\Multisite_Content_Sync\Domain\Mapping\ContentMapping;
use wpdb;

final readonly class WpdbMappingRepository implements MappingRepository {
	public function __construct( private wpdb $database ) {}

	public function find(
		int $connection_id,
		string $source_object_type,
		int $source_object_id,
	): ?ContentMapping {
		$query = $this->database->prepare(
			'SELECT * FROM %i
			WHERE connection_id = %d AND source_object_type = %s AND source_object_id = %d
			LIMIT 1',
			$this->table(),
			$connection_id,
			$source_object_type,
			$source_object_id,
		);
		$row   = $this->database->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	public function save( ContentMapping $mapping ): ContentMapping {
		$data    = array(
			'connection_id'         => $mapping->connection_id,
			'source_object_type'    => $mapping->source_object_type,
			'source_object_id'      => $mapping->source_object_id,
			'destination_object_id' => $mapping->destination_object_id,
			'source_hash'           => $mapping->source_hash,
			'destination_hash'      => $mapping->destination_hash,
			'status'                => $mapping->status,
			'last_synced_at'        => $mapping->last_synced_at?->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' );

		if ( null === $mapping->id ) {
			$result = $this->database->insert( $this->table(), $data, $formats );

			if ( false === $result ) {
				$existing = $this->find(
					$mapping->connection_id,
					$mapping->source_object_type,
					$mapping->source_object_id,
				);

				if ( null !== $existing ) {
					return $this->save(
						new ContentMapping(
							$existing->id,
							$mapping->connection_id,
							$mapping->source_object_type,
							$mapping->source_object_id,
							$mapping->destination_object_id,
							$mapping->source_hash,
							$mapping->destination_hash,
							$mapping->status,
							$mapping->last_synced_at,
						)
					);
				}

				throw new RuntimeException( 'Unable to save the content mapping.' );
			}

			return new ContentMapping(
				(int) $this->database->insert_id,
				$mapping->connection_id,
				$mapping->source_object_type,
				$mapping->source_object_id,
				$mapping->destination_object_id,
				$mapping->source_hash,
				$mapping->destination_hash,
				$mapping->status,
				$mapping->last_synced_at,
			);
		}

		$result = $this->database->update(
			$this->table(),
			$data,
			array( 'id' => $mapping->id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to update the content mapping.' );
		}

		return $mapping;
	}

	/**
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): ContentMapping {
		$timezone = new DateTimeZone( 'UTC' );

		return new ContentMapping(
			(int) $row['id'],
			(int) $row['connection_id'],
			(string) $row['source_object_type'],
			(int) $row['source_object_id'],
			! empty( $row['destination_object_id'] ) ? (int) $row['destination_object_id'] : null,
			! empty( $row['source_hash'] ) ? (string) $row['source_hash'] : null,
			! empty( $row['destination_hash'] ) ? (string) $row['destination_hash'] : null,
			(string) $row['status'],
			! empty( $row['last_synced_at'] ) ? new DateTimeImmutable( (string) $row['last_synced_at'], $timezone ) : null,
		);
	}

	private function table(): string {
		return $this->database->prefix . 'mcs_mappings';
	}
}
