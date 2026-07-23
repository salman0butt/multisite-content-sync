<?php
/**
 * WP-Cron queue scheduler.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress;

use SalmanButt\Multisite_Content_Sync\Application\Sync\QueueWorker;
use SalmanButt\Multisite_Content_Sync\Contracts\ActivityLogger;
use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;
use Throwable;
use WP_Error;

final readonly class QueueScheduler implements Hookable {
	public const HOOK = 'mcs_process_queue';

	public function __construct(
		private QueueWorker $worker,
		private ActivityLogger $logger,
	) {}

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'process' ) );
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_schedule( array $schedules ): array {
		$schedules['mcs_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute', 'multisite-content-sync' ),
		);

		return $schedules;
	}

	public function schedule(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$result = wp_schedule_event( time() + 60, 'mcs_minute', self::HOOK, array(), true );

		if ( $result instanceof WP_Error || false === $result ) {
			$this->logger->log(
				'error',
				'queue_schedule_failed',
				'The synchronization queue could not be scheduled.',
			);
		}
	}

	public function process(): void {
		try {
			$this->worker->run();
		} catch ( Throwable $error ) {
			$this->logger->log(
				'error',
				'queue_worker_failed',
				'The synchronization queue worker failed before completing its batch.',
				array( 'error_type' => $error::class ),
			);
		}
	}
}
