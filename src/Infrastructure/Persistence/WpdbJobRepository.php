<?php
/**
 * Wpdb durable job repository.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SalmanButt\Multisite_Content_Sync\Contracts\JobRepository;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\SyncJob;
use wpdb;

final readonly class WpdbJobRepository implements JobRepository {
	public function __construct( private wpdb $database ) {}

	public function enqueue(
		int $connection_id,
		string $object_type,
		int $object_id,
		string $operation,
		bool $force,
	): SyncJob {
		$active_key = SyncJob::active_key( $connection_id, $object_type, $object_id, $operation );
		$existing   = $this->find_by_active_key( $active_key );

		if ( null !== $existing ) {
			if ( $force && ! $existing->force ) {
				$updated = $this->database->update(
					$this->table(),
					array( 'force' => 1 ),
					array( 'id' => $existing->id ),
					array( '%d' ),
					array( '%d' ),
				);

				if ( false === $updated ) {
					throw new RuntimeException( 'Unable to promote the synchronization job to force overwrite.' );
				}

				$promoted = $this->find( $existing->id );

				if ( null === $promoted ) {
					throw new RuntimeException( 'The promoted synchronization job could not be reloaded.' );
				}

				return $promoted;
			}

			return $existing;
		}

		$now          = current_time( 'mysql', true );
		$request_uuid = wp_generate_uuid4();
		$result       = $this->database->insert(
			$this->table(),
			array(
				'connection_id' => $connection_id,
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'operation'     => $operation,
				'status'        => SyncJob::STATUS_PENDING,
				'attempts'      => 0,
				'available_at'  => $now,
				'locked_at'     => null,
				'request_uuid'  => $request_uuid,
				'active_key'    => $active_key,
				'force'         => $force ? 1 : 0,
				'last_error'    => null,
				'created_at'    => $now,
				'completed_at'  => null,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			$existing = $this->find_by_active_key( $active_key );

			if ( null !== $existing ) {
				return $existing;
			}

			throw new RuntimeException( 'Unable to enqueue the synchronization job.' );
		}

		$job = $this->find( (int) $this->database->insert_id );

		if ( null === $job ) {
			throw new RuntimeException( 'The synchronization job could not be reloaded.' );
		}

		return $job;
	}

	public function claim_batch(
		int $limit,
		DateTimeImmutable $now,
		DateTimeImmutable $stale_before,
	): array {
		$limit = max( 1, min( 50, $limit ) );

		$recovery_query = $this->database->prepare(
			'UPDATE %i
			SET status = %s, locked_at = NULL, available_at = %s
			WHERE status = %s AND locked_at IS NOT NULL AND locked_at < %s',
			$this->table(),
			SyncJob::STATUS_RETRYING,
			$this->format_date( $now ),
			SyncJob::STATUS_PROCESSING,
			$this->format_date( $stale_before ),
		);

		if ( null === $recovery_query ) {
			throw new RuntimeException( 'Unable to prepare stale job recovery.' );
		}

		$this->database->query( $recovery_query );

		$query = $this->database->prepare(
			'SELECT id FROM %i
			WHERE status IN (%s, %s) AND available_at <= %s AND locked_at IS NULL
			ORDER BY available_at ASC, id ASC
			LIMIT %d',
			$this->table(),
			SyncJob::STATUS_PENDING,
			SyncJob::STATUS_RETRYING,
			$this->format_date( $now ),
			$limit,
		);
		$ids   = $this->database->get_col( $query );
		$jobs  = array();

		foreach ( is_array( $ids ) ? $ids : array() as $candidate_id ) {
			$id          = (int) $candidate_id;
			$claim_query = $this->database->prepare(
				'UPDATE %i
				SET status = %s, locked_at = %s, attempts = attempts + 1
				WHERE id = %d AND status IN (%s, %s) AND locked_at IS NULL',
				$this->table(),
				SyncJob::STATUS_PROCESSING,
				$this->format_date( $now ),
				$id,
				SyncJob::STATUS_PENDING,
				SyncJob::STATUS_RETRYING,
			);

			if ( null === $claim_query ) {
				throw new RuntimeException( 'Unable to prepare a synchronization job claim.' );
			}

			$claimed = $this->database->query( $claim_query );

			if ( 1 !== $claimed ) {
				continue;
			}

			$job = $this->find( $id );

			if ( null !== $job ) {
				$jobs[] = $job;
			}
		}

		return $jobs;
	}

	public function complete( int $id, DateTimeImmutable $completed_at ): void {
		$this->finish( $id, SyncJob::STATUS_COMPLETED, $completed_at, null );
	}

	public function retry_later( int $id, DateTimeImmutable $available_at, string $error ): void {
		$result = $this->database->update(
			$this->table(),
			array(
				'status'       => SyncJob::STATUS_RETRYING,
				'available_at' => $this->format_date( $available_at ),
				'locked_at'    => null,
				'last_error'   => $this->sanitize_error( $error ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to reschedule the synchronization job.' );
		}
	}

	public function fail( int $id, DateTimeImmutable $failed_at, string $error ): void {
		$this->finish( $id, SyncJob::STATUS_FAILED, $failed_at, $error );
	}

	public function retry( int $id ): bool {
		$job = $this->find( $id );

		if ( null === $job || ! in_array( $job->status, array( SyncJob::STATUS_FAILED, SyncJob::STATUS_CANCELLED ), true ) ) {
			return false;
		}

		$active_key = SyncJob::active_key(
			$job->connection_id,
			$job->object_type,
			$job->object_id,
			$job->operation,
		);

		if ( null !== $this->find_by_active_key( $active_key ) ) {
			return false;
		}

		$result = $this->database->update(
			$this->table(),
			array(
				'status'       => SyncJob::STATUS_PENDING,
				'attempts'     => 0,
				'available_at' => current_time( 'mysql', true ),
				'locked_at'    => null,
				'active_key'   => $active_key,
				'last_error'   => null,
				'completed_at' => null,
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return 1 === $result;
	}

	public function cancel( int $id ): bool {
		$query = $this->database->prepare(
			'UPDATE %i
			SET status = %s, active_key = NULL, locked_at = NULL, completed_at = %s
			WHERE id = %d AND status IN (%s, %s)',
			$this->table(),
			SyncJob::STATUS_CANCELLED,
			current_time( 'mysql', true ),
			$id,
			SyncJob::STATUS_PENDING,
			SyncJob::STATUS_RETRYING,
		);

		if ( null === $query ) {
			throw new RuntimeException( 'Unable to prepare synchronization job cancellation.' );
		}

		return 1 === $this->database->query( $query );
	}

	public function latest( int $limit = 100 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$query = $this->database->prepare(
			'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
			$this->table(),
			$limit,
		);
		$rows  = $this->database->get_results( $query, ARRAY_A );
		$jobs  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$jobs[] = $this->hydrate( $row );
			}
		}

		return $jobs;
	}

	private function finish(
		int $id,
		string $status,
		DateTimeImmutable $completed_at,
		?string $error,
	): void {
		$result = $this->database->update(
			$this->table(),
			array(
				'status'       => $status,
				'active_key'   => null,
				'locked_at'    => null,
				'last_error'   => null === $error ? null : $this->sanitize_error( $error ),
				'completed_at' => $this->format_date( $completed_at ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to finish the synchronization job.' );
		}
	}

	private function find( int $id ): ?SyncJob {
		$query = $this->database->prepare(
			'SELECT * FROM %i WHERE id = %d',
			$this->table(),
			$id,
		);
		$row   = $this->database->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	private function find_by_active_key( string $active_key ): ?SyncJob {
		$query = $this->database->prepare(
			'SELECT * FROM %i WHERE active_key = %s LIMIT 1',
			$this->table(),
			$active_key,
		);
		$row   = $this->database->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): SyncJob {
		$timezone = new DateTimeZone( 'UTC' );

		return new SyncJob(
			(int) $row['id'],
			(int) $row['connection_id'],
			(string) $row['object_type'],
			(int) $row['object_id'],
			(string) $row['operation'],
			(string) $row['status'],
			(int) $row['attempts'],
			new DateTimeImmutable( (string) $row['available_at'], $timezone ),
			! empty( $row['locked_at'] ) ? new DateTimeImmutable( (string) $row['locked_at'], $timezone ) : null,
			(string) $row['request_uuid'],
			(bool) $row['force'],
			! empty( $row['last_error'] ) ? (string) $row['last_error'] : null,
		);
	}

	private function table(): string {
		return $this->database->prefix . 'mcs_jobs';
	}

	private function format_date( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private function sanitize_error( string $error ): string {
		return substr( wp_strip_all_tags( $error ), 0, 2000 );
	}
}
