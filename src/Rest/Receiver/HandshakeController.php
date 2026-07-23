<?php
/**
 * Receiver handshake endpoint.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Rest\Receiver;

use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class HandshakeController implements Hookable {
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'mcs/v1',
			'/receiver/handshake',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_receive' ),
			)
		);
	}

	public function can_receive( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'mcs_receive_content' );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$post_types = array_values(
			array_map(
				static fn ( \WP_Post_Type $post_type ): array => array(
					'name'  => $post_type->name,
					'label' => $post_type->label,
				),
				get_post_types( array( 'show_in_rest' => true ), 'objects' )
			)
		);

		return new WP_REST_Response(
			array(
				'site_uuid'      => (string) get_option( 'mcs_site_uuid' ),
				'site_url'       => home_url( '/' ),
				'plugin_version' => MCS_VERSION,
				'wordpress'      => get_bloginfo( 'version' ),
				'php'            => PHP_VERSION,
				'post_types'     => $post_types,
			),
			200
		);
	}
}
