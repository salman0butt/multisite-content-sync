<?php
/**
 * Optional automatic post-save synchronization.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress;

use SalmanButt\Multisite_Content_Sync\Application\Sync\EnqueueSync;
use SalmanButt\Multisite_Content_Sync\Contracts\ActivityLogger;
use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;
use Throwable;
use WP_Post;

final readonly class AutoSyncSubscriber implements Hookable {
	public function __construct(
		private EnqueueSync $enqueue_sync,
		private ActivityLogger $logger,
	) {}

	public function register_hooks(): void {
		add_action( 'transition_post_status', array( $this, 'maybe_enqueue' ), 20, 3 );
	}

	public function maybe_enqueue( string $new_status, string $old_status, WP_Post $post ): void {
		unset( $old_status );

		if (
			'publish' !== $new_status
			|| ! in_array( $post->post_type, array( 'post', 'page' ), true )
			|| wp_is_post_revision( $post->ID )
			|| wp_is_post_autosave( $post->ID )
			|| IncomingSyncGuard::is_active()
		) {
			return;
		}

		/**
		 * Filters destination IDs for automatic synchronization.
		 *
		 * Return an empty array to disable automatic sync, which is the default.
		 *
		 * @param list<int> $connection_ids Destination connection IDs.
		 * @param WP_Post   $post           Saved source post.
		 */
		$connection_ids = apply_filters( 'mcs_auto_sync_connection_ids', array(), $post );

		if ( ! is_array( $connection_ids ) || array() === $connection_ids ) {
			return;
		}

		try {
			$this->enqueue_sync->execute( $post->ID, array_values( array_map( 'intval', $connection_ids ) ) );
		} catch ( Throwable $error ) {
			$this->logger->log(
				'error',
				'auto_sync_enqueue_failed',
				'Automatic synchronization could not be queued.',
				array(
					'post_id'    => $post->ID,
					'error_type' => $error::class,
				),
			);
		}
	}
}
