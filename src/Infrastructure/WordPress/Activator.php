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
use WP_Role;

final class Activator {
	public static function activate( bool $network_wide = false ): void {
		Requirements::assert_for_activation();

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
					self::activate_current_site();
				} finally {
					restore_current_blog();
				}
			}

			return;
		}

		self::activate_current_site();
	}

	public static function activate_current_site(): void {
		global $wpdb;

		( new Schema( $wpdb ) )->install();
		self::ensure_current_site_configuration();
	}

	public static function ensure_current_site_configuration(): void {
		$site_uuid = (string) get_option( 'mcs_site_uuid', '' );

		if ( ! self::is_uuid( $site_uuid ) ) {
			update_option( 'mcs_site_uuid', wp_generate_uuid4(), false );
		}

		$administrator = get_role( 'administrator' );

		if ( $administrator instanceof WP_Role && ! $administrator->has_cap( 'mcs_receive_content' ) ) {
			$administrator->add_cap( 'mcs_receive_content' );
		}

		$receiver = get_role( 'mcs_receiver' );

		if ( ! $receiver instanceof WP_Role ) {
			$receiver = add_role(
				'mcs_receiver',
				__( 'Content Sync Receiver', 'multisite-content-sync' ),
				array( 'read' => true )
			);
		}

		if ( $receiver instanceof WP_Role && ! $receiver->has_cap( 'read' ) ) {
			$receiver->add_cap( 'read' );
		}

		if ( $receiver instanceof WP_Role && ! $receiver->has_cap( 'mcs_receive_content' ) ) {
			$receiver->add_cap( 'mcs_receive_content' );
		}
	}

	private static function is_uuid( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}
}
