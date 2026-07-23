<?php
/**
 * Remove plugin data when explicitly uninstalled.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$remove_site_data = static function (): void {
	global $wpdb;

	foreach ( array( 'connections', 'rules', 'mappings', 'jobs', 'logs' ) as $suffix ) {
		$table = $wpdb->prefix . 'mcs_' . $suffix;
		$wpdb->query(
			$wpdb->prepare(
				'DROP TABLE IF EXISTS %i',
				$table,
			)
		);
	}

	delete_option( 'mcs_schema_version' );
	delete_option( 'mcs_site_uuid' );
	wp_clear_scheduled_hook( 'mcs_process_queue' );

	$administrator = get_role( 'administrator' );
	$administrator?->remove_cap( 'mcs_receive_content' );
	remove_role( 'mcs_receiver' );
};

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );

		try {
			$remove_site_data();
		} finally {
			restore_current_blog();
		}
	}
} else {
	$remove_site_data();
}
