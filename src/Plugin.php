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
use SalmanButt\Multisite_Content_Sync\Application\Content\ContentPayloadFactory;
use SalmanButt\Multisite_Content_Sync\Application\Content\ContentReceiver;
use SalmanButt\Multisite_Content_Sync\Application\Content\TermSynchronizer;
use SalmanButt\Multisite_Content_Sync\Application\Sync\EnqueueSync;
use SalmanButt\Multisite_Content_Sync\Application\Sync\QueueWorker;
use SalmanButt\Multisite_Content_Sync\Application\Sync\RetryPolicy;
use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Database\Schema;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Http\WordPressRemoteSiteClient;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Media\BlockContentRewriter;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Media\MediaImporter;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Persistence\WpdbActivityLogger;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Persistence\WpdbConnectionRepository;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Persistence\WpdbJobRepository;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Persistence\WpdbMappingRepository;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Security\OpenSslCredentialCipher;
use SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress\Activator;
use SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress\AutoSyncSubscriber;
use SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress\QueueScheduler;
use SalmanButt\Multisite_Content_Sync\Rest\Admin\ConnectionsController;
use SalmanButt\Multisite_Content_Sync\Rest\Admin\JobsController;
use SalmanButt\Multisite_Content_Sync\Rest\Admin\SyncController;
use SalmanButt\Multisite_Content_Sync\Rest\Receiver\ContentController;
use SalmanButt\Multisite_Content_Sync\Rest\Receiver\HandshakeController;

final class Plugin {
	/**
	 * @var list<Hookable>
	 */
	private array $services = array();

	public function boot(): void {
		global $wpdb;

		$this->maybe_upgrade_schema( new Schema( $wpdb ) );
		load_plugin_textdomain( 'multisite-content-sync', false, dirname( MCS_BASENAME ) . '/languages' );
		Activator::ensure_current_site_configuration();

		$cipher      = new OpenSslCredentialCipher();
		$connections = new WpdbConnectionRepository( $wpdb );
		$jobs        = new WpdbJobRepository( $wpdb );
		$mappings    = new WpdbMappingRepository( $wpdb );
		$logger      = new WpdbActivityLogger( $wpdb );
		$remote      = new WordPressRemoteSiteClient( $cipher );

		$create_connection = new CreateConnection( $connections, $cipher );
		$delete_connection = new DeleteConnection( $connections );
		$test_connection   = new TestConnection( $connections, $remote );
		$enqueue_sync      = new EnqueueSync( $connections, $jobs );
		$payload_factory   = new ContentPayloadFactory();
		$queue_worker      = new QueueWorker(
			$jobs,
			$connections,
			$mappings,
			$remote,
			$payload_factory,
			new RetryPolicy(),
			$logger,
		);
		$content_receiver = new ContentReceiver(
			new MediaImporter(),
			new BlockContentRewriter(),
			new TermSynchronizer(),
		);

		$this->services = array(
			new Menu( $connections ),
			new HandshakeController(),
			new ContentController( $content_receiver, $logger ),
			new ConnectionsController(
				$connections,
				$create_connection,
				$delete_connection,
				$test_connection,
			),
			new SyncController( $enqueue_sync ),
			new JobsController( $jobs ),
			new QueueScheduler( $queue_worker, $logger ),
			new AutoSyncSubscriber( $enqueue_sync, $logger ),
		);

		foreach ( $this->services as $service ) {
			$service->register_hooks();
		}
	}

	private function maybe_upgrade_schema( Schema $schema ): void {
		if ( Schema::VERSION !== (string) get_option( 'mcs_schema_version', '' ) ) {
			$schema->install();
		}
	}
}
