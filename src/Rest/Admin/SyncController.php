<?php
/**
 * Manual synchronization enqueue endpoint.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Rest\Admin;

use InvalidArgumentException;
use SalmanButt\Multisite_Content_Sync\Application\Sync\EnqueueSync;
use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\SyncJob;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final readonly class SyncController implements Hookable {
	public function __construct( private EnqueueSync $enqueue_sync ) {}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'mcs/v1',
			'/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'enqueue' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'connection_ids' => array(
						'type'     => 'array',
						'default'  => array(),
						'maxItems' => 100,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	public function can_manage( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'manage_options' );
	}

	public function enqueue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connection_ids = $request->get_param( 'connection_ids' );

		try {
			$jobs = $this->enqueue_sync->execute(
				(int) $request->get_param( 'post_id' ),
				is_array( $connection_ids ) ? array_map( 'intval', $connection_ids ) : array(),
				(bool) $request->get_param( 'force' ),
			);

			return new WP_REST_Response(
				array(
					'jobs' => array_map( $this->serialize( ... ), $jobs ),
				),
				202
			);
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error(
				'mcs_sync_not_queued',
				sanitize_text_field( $error->getMessage() ),
				array( 'status' => 400 )
			);
		} catch ( Throwable ) {
			return new WP_Error(
				'mcs_sync_enqueue_failed',
				__( 'Unable to enqueue synchronization.', 'multisite-content-sync' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serialize( SyncJob $job ): array {
		return array(
			'id'            => $job->id,
			'connection_id' => $job->connection_id,
			'object_type'   => $job->object_type,
			'object_id'     => $job->object_id,
			'status'        => $job->status,
			'attempts'      => $job->attempts,
			'request_uuid'  => $job->request_uuid,
			'force'         => $job->force,
			'available_at'  => $job->available_at->format( DATE_ATOM ),
		);
	}
}
