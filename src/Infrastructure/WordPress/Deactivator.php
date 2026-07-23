<?php
/**
 * Plugin deactivation.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress;

final class Deactivator {
	public static function deactivate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );

				try {
					wp_clear_scheduled_hook( QueueScheduler::HOOK );
				} finally {
					restore_current_blog();
				}
			}

			return;
		}

		wp_clear_scheduled_hook( QueueScheduler::HOOK );
	}
}
