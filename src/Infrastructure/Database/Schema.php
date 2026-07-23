<?php
/**
 * Database schema installer.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\Database;

use wpdb;

final readonly class Schema {
	public const VERSION = '1';

	public function __construct( private wpdb $database ) {}

	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->database->get_charset_collate();
		$prefix          = $this->database->prefix . 'mcs_';

		$sql = array(
			"CREATE TABLE {$prefix}connections (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				site_url varchar(255) NOT NULL,
				username varchar(100) NOT NULL,
				encrypted_credential longtext NOT NULL,
				remote_site_uuid char(36) NULL,
				remote_plugin_version varchar(30) NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				last_checked_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY site_url (site_url),
				KEY status (status)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}rules (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				post_type varchar(100) NOT NULL,
				settings longtext NOT NULL,
				enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY post_type (post_type),
				KEY enabled (enabled)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}mappings (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				connection_id bigint(20) unsigned NOT NULL,
				source_object_type varchar(100) NOT NULL,
				source_object_id bigint(20) unsigned NOT NULL,
				destination_object_id bigint(20) unsigned NULL,
				source_hash char(64) NULL,
				destination_hash char(64) NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				last_synced_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_mapping (connection_id,source_object_type,source_object_id),
				KEY destination_object_id (destination_object_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}jobs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				connection_id bigint(20) unsigned NOT NULL,
				object_type varchar(100) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				operation varchar(30) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				available_at datetime NOT NULL,
				locked_at datetime NULL,
				request_uuid char(36) NOT NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				completed_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY request_uuid (request_uuid),
				KEY queue_lookup (status,available_at),
				KEY connection_id (connection_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}logs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				level varchar(20) NOT NULL,
				event varchar(100) NOT NULL,
				message text NOT NULL,
				context longtext NULL,
				request_uuid char(36) NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event (event),
				KEY level (level),
				KEY request_uuid (request_uuid),
				KEY created_at (created_at)
			) {$charset_collate};",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'mcs_schema_version', self::VERSION, false );
	}
}
