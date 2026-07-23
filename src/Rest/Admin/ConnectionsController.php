<?php
/**
 * Connection administration REST endpoints.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Rest\Admin;

use InvalidArgumentException;
use SalmanButt\Multisite_Content_Sync\Application\Connection\CreateConnection;
use SalmanButt\Multisite_Content_Sync\Application\Connection\DeleteConnection;
use SalmanButt\Multisite_Content_Sync\Application\Connection\TestConnection;
use SalmanButt\Multisite_Content_Sync\Contracts\ConnectionRepository;
use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;
use SalmanButt\Multisite_Content_Sync\Domain\Connection\Connection;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final readonly class ConnectionsController implements Hookable {
	public function __construct(
		private ConnectionRepository $connections,
		private CreateConnection $create_connection,
		private DeleteConnection $delete_connection,
		private TestConnection $test_connection,
	) {}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'mcs/v1',
			'/connections',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $this->create_args(),
				),
			)
		);

		register_rest_route(
			'mcs/v1',
			'/connections/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);

		register_rest_route(
			'mcs/v1',
			'/connections/(?P<id>\d+)/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
	}

	public function can_manage( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'manage_options' );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response( array_map( $this->serialize( ... ), $this->connections->all() ) );
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$connection = $this->create_connection->execute(
				(string) $request->get_param( 'name' ),
				(string) $request->get_param( 'site_url' ),
				(string) $request->get_param( 'username' ),
				(string) $request->get_param( 'application_password' ),
			);

			return new WP_REST_Response( $this->serialize( $connection ), 201 );
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'mcs_invalid_connection', sanitize_text_field( $exception->getMessage() ), array( 'status' => 400 ) );
		} catch ( Throwable ) {
			return new WP_Error(
				'mcs_connection_create_failed',
				__( 'Unable to create the connection.', 'multisite-content-sync' ),
				array( 'status' => 500 )
			);
		}
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->delete_connection->execute( $id ) ) {
			return new WP_Error(
				'mcs_connection_delete_failed',
				__( 'Unable to delete the connection.', 'multisite-content-sync' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( null, 204 );
	}

	public function test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $this->test_connection->execute( (int) $request->get_param( 'id' ) ) );
		} catch ( Throwable $throwable ) {
			return new WP_Error(
				'mcs_connection_test_failed',
				sanitize_text_field( $throwable->getMessage() ),
				array( 'status' => 502 )
			);
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function create_args(): array {
		return array(
			'name'                 => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 1,
			),
			'site_url'             => array(
				'type'     => 'string',
				'required' => true,
				'format'   => 'uri',
			),
			'username'             => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 1,
			),
			'application_password' => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 1,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serialize( Connection $connection ): array {
		return array(
			'id'                    => $connection->id,
			'name'                  => $connection->name,
			'site_url'              => $connection->site_url,
			'username'              => $connection->username,
			'status'                => $connection->status->value,
			'remote_site_uuid'      => $connection->remote_site_uuid,
			'remote_plugin_version' => $connection->remote_plugin_version,
			'last_checked_at'       => $connection->last_checked_at?->format( DATE_ATOM ),
		);
	}
}
