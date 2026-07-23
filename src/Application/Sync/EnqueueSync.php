<?php
/**
 * Enqueue synchronization jobs.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Application\Sync;

use InvalidArgumentException;
use SalmanButt\Multisite_Content_Sync\Contracts\ConnectionRepository;
use SalmanButt\Multisite_Content_Sync\Contracts\JobRepository;
use SalmanButt\Multisite_Content_Sync\Domain\Connection\ConnectionStatus;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\SyncJob;
use WP_Post;

final readonly class EnqueueSync {
	public function __construct(
		private ConnectionRepository $connections,
		private JobRepository $jobs,
	) {}

	/**
	 * @param list<int> $connection_ids Destination connection IDs. Empty means all connected sites.
	 * @return list<SyncJob>
	 */
	public function execute( int $post_id, array $connection_ids = array(), bool $force = false ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			throw new InvalidArgumentException( 'The selected post or page does not exist.' );
		}

		if (
			wp_is_post_revision( $post_id )
			|| ! in_array( $post->post_status, array( 'draft', 'pending', 'private', 'publish' ), true )
		) {
			throw new InvalidArgumentException( 'The selected content status cannot be synchronized.' );
		}

		$connection_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $connection_ids ),
					static fn ( int $id ): bool => $id > 0
				)
			)
		);

		if ( count( $connection_ids ) > 100 ) {
			throw new InvalidArgumentException( 'A maximum of 100 destination connections can be queued at once.' );
		}

		if ( array() === $connection_ids ) {
			foreach ( $this->connections->all() as $connection ) {
				if ( null !== $connection->id && ConnectionStatus::Connected === $connection->status ) {
					$connection_ids[] = $connection->id;
				}
			}
		}

		if ( array() === $connection_ids ) {
			throw new InvalidArgumentException( 'No connected destination sites are available.' );
		}

		foreach ( $connection_ids as $connection_id ) {
			$connection = $this->connections->find( $connection_id );

			if ( null === $connection || ConnectionStatus::Connected !== $connection->status ) {
				throw new InvalidArgumentException( 'One or more destination connections are unavailable.' );
			}
		}

		$queued = array();

		foreach ( $connection_ids as $connection_id ) {
			$queued[] = $this->jobs->enqueue(
				$connection_id,
				$post->post_type,
				$post->ID,
				'upsert',
				$force,
			);
		}

		return $queued;
	}
}
