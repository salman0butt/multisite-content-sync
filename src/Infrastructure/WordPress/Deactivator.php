<?php
/**
 * Plugin deactivation.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress;

final class Deactivator {
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'mcs_process_queue' );
	}
}
