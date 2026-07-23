<?php
/**
 * Receiver handshake endpoint.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Rest\Receiver;

use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;
use WP_Post_Type;
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

		$site_uuid = (string) get_option( 'mcs_site_uuid', '' );

		if ( ! $this->is_uuid( $site_uuid ) ) {
			$site_uuid = wp_generate_uuid4();
			update_option( 'mcs_site_uuid', $site_uuid, false );
		}

		$post_types = array();

		foreach ( array( 'post', 'page' ) as $post_type_name ) {
			$post_type = get_post_type_object( $post_type_name );

			if ( $post_type instanceof WP_Post_Type ) {
				$post_types[] = array(
					'name'  => $post_type->name,
					'label' => $post_type->label,
				);
			}
		}

		return new WP_REST_Response(
			array(
				'site_uuid'             => $site_uuid,
				'site_url'              => home_url( '/' ),
				'plugin_version'        => MCS_VERSION,
				'wordpress'             => get_bloginfo( 'version' ),
				'php'                   => PHP_VERSION,
				'application_passwords' => wp_is_application_passwords_available(),
				'post_types'            => $post_types,
				'taxonomies'            => array( 'category', 'post_tag' ),
				'features'              => array(
					'content_upsert'     => true,
					'conflict_detection' => true,
					'media'              => true,
					'taxonomies'         => true,
				),
				'limits'                => array(
					'media_bytes' => 10485760,
					'media_items' => 50,
				),
			),
			200
		);
	}

	private function is_uuid( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}
}
