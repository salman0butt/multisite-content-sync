<?php
/**
 * Plugin composition root.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync;

use SalmanButt\Multisite_Content_Sync\Admin\Menu;
use SalmanButt\Multisite_Content_Sync\Application\Connection\CreateConnection;
use SalmanButt\Multisite_Content_Sync\Application\Connection\DeleteConnection;
use SalmanButt\Multisite_Content_Sync\Application\Connection\TestConnection;
use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Database\Schema;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Http\WordPressRemoteSiteClient;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Persistence\WpdbConnectionRepository;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Security\OpenSslCredentialCipher;
use SalmanButt\Multisite_Content_Sync\Rest\Admin\ConnectionsController;
use SalmanButt\Multisite_Content_Sync\Rest\Receiver\HandshakeController;

final class Plugin {
	/**
	 * @var list<Hookable>
	 */
	private array $services = array();

	public function boot(): void {
		global $wpdb;

		$cipher      = new OpenSslCredentialCipher();
		$connections = new WpdbConnectionRepository( $wpdb );
		$remote      = new WordPressRemoteSiteClient( $cipher );

		$create = new CreateConnection( $connections, $cipher );
		$delete = new DeleteConnection( $connections );
		$test   = new TestConnection( $connections, $remote );

		$this->services = array(
			new Menu( $connections ),
			new HandshakeController(),
			new ConnectionsController( $connections, $create, $delete, $test ),
		);

		foreach ( $this->services as $service ) {
			$service->register_hooks();
		}

		$this->maybe_upgrade_schema( new Schema( $wpdb ) );
		load_plugin_textdomain( 'multisite-content-sync', false, dirname( MCS_BASENAME ) . '/languages' );
	}

	private function maybe_upgrade_schema( Schema $schema ): void {
		if ( Schema::VERSION !== (string) get_option( 'mcs_schema_version', '' ) ) {
			$schema->install();
		}
	}
}
