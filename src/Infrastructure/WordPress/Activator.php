<?php
/**
 * Plugin activation.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress;

use SalmanButt\Multisite_Content_Sync\Infrastructure\Database\Schema;
use SalmanButt\Multisite_Content_Sync\Support\Requirements;

final class Activator {
	public static function activate(): void {
		Requirements::assert_for_activation();

		global $wpdb;

		( new Schema( $wpdb ) )->install();

		if ( false === get_option( 'mcs_site_uuid', false ) ) {
			add_option( 'mcs_site_uuid', wp_generate_uuid4(), '', false );
		}

		$administrator = get_role( 'administrator' );
		$administrator?->add_cap( 'mcs_receive_content' );

		add_role(
			'mcs_receiver',
			__( 'Content Sync Receiver', 'multisite-content-sync' ),
			array(
				'read'                => true,
				'mcs_receive_content' => true,
			)
		);
	}
}
