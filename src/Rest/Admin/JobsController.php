<?php
/**
 * Queue administration REST endpoints.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Rest\Admin;

use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;
use SalmanButt\Multisite_Content_Sync\Contracts\JobRepository;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\SyncJob;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final readonly class JobsController implements Hookable {
	public function __construct( private JobRepository $jobs ) {}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'mcs/v1',
			'/jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 100,
						'minimum' => 1,
						'maximum' => 500,
					),
				),
			)
		);

		register_rest_route(
			'mcs/v1',
			'/jobs/(?P<id>\d+)/retry',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'retry' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $this->id_argument(),
			)
		);

		register_rest_route(
			'mcs/v1',
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $this->id_argument(),
			)
		);
	}

	public function can_manage( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'manage_options' );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$jobs = $this->jobs->latest( (int) $request->get_param( 'limit' ) );

		return new WP_REST_Response( array_map( $this->serialize( ... ), $jobs ) );
	}

	public function retry( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->jobs->retry( (int) $request->get_param( 'id' ) ) ) {
			return new WP_Error(
				'mcs_job_retry_failed',
				__( 'The job cannot be retried.', 'multisite-content-sync' ),
				array( 'status' => 409 )
			);
		}

		return new WP_REST_Response( array( 'retried' => true ) );
	}

	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->jobs->cancel( (int) $request->get_param( 'id' ) ) ) {
			return new WP_Error(
				'mcs_job_cancel_failed',
				__( 'The job cannot be cancelled.', 'multisite-content-sync' ),
				array( 'status' => 409 )
			);
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function id_argument(): array {
		return array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
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
			'operation'     => $job->operation,
			'status'        => $job->status,
			'attempts'      => $job->attempts,
			'available_at'  => $job->available_at->format( DATE_ATOM ),
			'locked_at'     => $job->locked_at?->format( DATE_ATOM ),
			'request_uuid'  => $job->request_uuid,
			'force'         => $job->force,
			'last_error'    => $job->last_error,
		);
	}
}
