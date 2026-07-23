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

global $wpdb;

$tables = array( 'connections', 'rules', 'mappings', 'jobs', 'logs' );

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcs_{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal fixed table names only.
}

delete_option( 'mcs_schema_version' );
delete_option( 'mcs_site_uuid' );

$administrator = get_role( 'administrator' );
$administrator?->remove_cap( 'mcs_receive_content' );
remove_role( 'mcs_receiver' );
