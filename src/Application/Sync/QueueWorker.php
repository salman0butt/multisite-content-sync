<?php
/**
 * Process durable synchronization jobs.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Application\Sync;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SalmanButt\Multisite_Content_Sync\Application\Content\ContentPayloadFactory;
use SalmanButt\Multisite_Content_Sync\Contracts\ActivityLogger;
use SalmanButt\Multisite_Content_Sync\Contracts\ConnectionRepository;
use SalmanButt\Multisite_Content_Sync\Contracts\JobRepository;
use SalmanButt\Multisite_Content_Sync\Contracts\MappingRepository;
use SalmanButt\Multisite_Content_Sync\Contracts\RemoteSiteClient;
use SalmanButt\Multisite_Content_Sync\Domain\Mapping\ContentMapping;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\RemoteSyncException;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\SyncJob;
use Throwable;
use WP_Post;

final readonly class QueueWorker {
	public function __construct(
		private JobRepository $jobs,
		private ConnectionRepository $connections,
		private MappingRepository $mappings,
		private RemoteSiteClient $remote_sites,
		private ContentPayloadFactory $payload_factory,
		private RetryPolicy $retry_policy,
		private ActivityLogger $logger,
	) {}

	public function run(): void {
		$now          = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$stale_before = $now->modify( '-15 minutes' );
		$batch_size   = (int) apply_filters( 'mcs_queue_batch_size', 5 );
		$batch_size   = max( 1, min( 25, $batch_size ) );

		foreach ( $this->jobs->claim_batch( $batch_size, $now, $stale_before ) as $job ) {
			$this->process( $job );
		}
	}

	private function process( SyncJob $job ): void {
		$now     = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$payload = null;

		try {
			$connection = $this->connections->find( $job->connection_id );

			if ( null === $connection ) {
				throw new InvalidArgumentException( 'The destination connection no longer exists.' );
			}

			$post = get_post( $job->object_id );

			if ( ! $post instanceof WP_Post || $post->post_type !== $job->object_type ) {
				throw new InvalidArgumentException( 'The source content no longer exists.' );
			}

			$payload = $this->payload_factory->build( $post );
			$result  = $this->remote_sites->sync_content( $connection, $payload, $job->force );

			$destination_id   = isset( $result['destination_object_id'] ) ? (int) $result['destination_object_id'] : 0;
			$destination_hash = isset( $result['destination_hash'] ) ? (string) $result['destination_hash'] : '';

			if ( $destination_id <= 0 || 1 !== preg_match( '/^[a-f0-9]{64}$/', $destination_hash ) ) {
				throw new RemoteSyncException( 'The destination returned an invalid synchronization response.', 502 );
			}

			$existing_mapping = $this->mappings->find(
				$job->connection_id,
				$job->object_type,
				$job->object_id,
			);

			$this->mappings->save(
				new ContentMapping(
					$existing_mapping?->id,
					$job->connection_id,
					$job->object_type,
					$job->object_id,
					$destination_id,
					(string) $payload['source_hash'],
					$destination_hash,
					ContentMapping::STATUS_SYNCED,
					$now,
				)
			);
			$this->jobs->complete( $job->id, $now );
			$this->logger->log(
				'info',
				'sync_completed',
				'Content synchronization completed.',
				array(
					'job_id'                => $job->id,
					'connection_id'         => $job->connection_id,
					'source_object_id'      => $job->object_id,
					'destination_object_id' => $destination_id,
					'no_change'             => (bool) ( $result['no_change'] ?? false ),
				),
				$job->request_uuid,
			);
		} catch ( Throwable $error ) {
			$this->handle_failure( $job, $error, $payload, $now );
		}
	}

	/**
	 * @param array<string, mixed>|null $payload Outgoing payload, when available.
	 */
	private function handle_failure(
		SyncJob $job,
		Throwable $error,
		?array $payload,
		DateTimeImmutable $now,
	): void {
		$message = substr( wp_strip_all_tags( $error->getMessage() ), 0, 2000 );

		if ( $error instanceof RemoteSyncException && $error->is_conflict() ) {
			$existing_mapping = $this->mappings->find(
				$job->connection_id,
				$job->object_type,
				$job->object_id,
			);
			$destination_id   = isset( $error->details['destination_object_id'] )
				? (int) $error->details['destination_object_id']
				: $existing_mapping?->destination_object_id;
			$destination_hash = isset( $error->details['destination_hash'] )
				? (string) $error->details['destination_hash']
				: $existing_mapping?->destination_hash;

			$this->save_mapping_safely(
				$job,
				new ContentMapping(
					$existing_mapping?->id,
					$job->connection_id,
					$job->object_type,
					$job->object_id,
					$destination_id,
					is_array( $payload ) ? (string) ( $payload['source_hash'] ?? '' ) : $existing_mapping?->source_hash,
					$destination_hash,
					ContentMapping::STATUS_CONFLICT,
					$existing_mapping?->last_synced_at,
				)
			);
			$this->jobs->fail( $job->id, $now, $message );
			$this->logger->log(
				'warning',
				'sync_conflict',
				'Destination changes prevented synchronization.',
				array(
					'job_id'        => $job->id,
					'connection_id' => $job->connection_id,
					'object_id'     => $job->object_id,
				),
				$job->request_uuid,
			);
			return;
		}

		if (
			! $error instanceof InvalidArgumentException
			&& $this->retry_policy->can_retry( $job->attempts, $error )
		) {
			$available_at = $this->retry_policy->next_available_at( $job->attempts, $now );
			$this->jobs->retry_later( $job->id, $available_at, $message );
			$this->logger->log(
				'warning',
				'sync_retry_scheduled',
				'Content synchronization will be retried.',
				array(
					'job_id'       => $job->id,
					'attempts'     => $job->attempts,
					'available_at' => $available_at->format( DATE_ATOM ),
					'error_type'   => $error::class,
				),
				$job->request_uuid,
			);
			return;
		}

		$existing_mapping = $this->mappings->find(
			$job->connection_id,
			$job->object_type,
			$job->object_id,
		);

		if ( null !== $existing_mapping || is_array( $payload ) ) {
			$this->save_mapping_safely(
				$job,
				new ContentMapping(
					$existing_mapping?->id,
					$job->connection_id,
					$job->object_type,
					$job->object_id,
					$existing_mapping?->destination_object_id,
					is_array( $payload ) ? (string) ( $payload['source_hash'] ?? '' ) : $existing_mapping?->source_hash,
					$existing_mapping?->destination_hash,
					ContentMapping::STATUS_FAILED,
					$existing_mapping?->last_synced_at,
				)
			);
		}

		$this->jobs->fail( $job->id, $now, $message );
		$this->logger->log(
			'error',
			'sync_failed',
			'Content synchronization failed.',
			array(
				'job_id'        => $job->id,
				'connection_id' => $job->connection_id,
				'object_id'     => $job->object_id,
				'attempts'      => $job->attempts,
				'error_type'    => $error::class,
				'message'       => $message,
			),
			$job->request_uuid,
		);
	}

	private function save_mapping_safely( SyncJob $job, ContentMapping $mapping ): void {
		try {
			$this->mappings->save( $mapping );
		} catch ( Throwable $error ) {
			$this->logger->log(
				'error',
				'mapping_persistence_failed',
				'The synchronization mapping state could not be persisted.',
				array(
					'job_id'        => $job->id,
					'connection_id' => $job->connection_id,
					'object_id'     => $job->object_id,
					'error_type'    => $error::class,
				),
				$job->request_uuid,
			);
		}
	}
}
