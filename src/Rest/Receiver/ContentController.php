<?php
/**
 * Destination content receiver endpoint.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Rest\Receiver;

use InvalidArgumentException;
use SalmanButt\Multisite_Content_Sync\Application\Content\ContentReceiver;
use SalmanButt\Multisite_Content_Sync\Contracts\ActivityLogger;
use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;
use SalmanButt\Multisite_Content_Sync\Domain\Content\SyncConflictException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final readonly class ContentController implements Hookable {
	public function __construct(
		private ContentReceiver $receiver,
		private ActivityLogger $logger,
	) {}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'mcs/v1',
			'/receiver/content',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_receive' ),
				'args'                => $this->arguments(),
			)
		);
	}

	public function can_receive( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'mcs_receive_content' );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'mcs_invalid_payload',
				__( 'A JSON content payload is required.', 'multisite-content-sync' ),
				array( 'status' => 400 )
			);
		}

		try {
			$result = $this->receiver->upsert( $payload, (bool) $request->get_param( 'force' ) );
			return new WP_REST_Response( $result, 200 );
		} catch ( SyncConflictException $conflict ) {
			return new WP_Error(
				'mcs_sync_conflict',
				__( 'The destination content has local changes and was not overwritten.', 'multisite-content-sync' ),
				array(
					'status'                => 409,
					'destination_object_id' => $conflict->destination_id,
					'destination_hash'      => $conflict->destination_hash,
				)
			);
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error(
				'mcs_invalid_payload',
				sanitize_text_field( $error->getMessage() ),
				array( 'status' => 400 )
			);
		} catch ( Throwable $error ) {
			$this->logger->log(
				'error',
				'receiver_content_failed',
				'The destination content receiver failed.',
				array( 'error_type' => $error::class ),
			);

			return new WP_Error(
				'mcs_receiver_failed',
				__( 'The destination could not process the synchronization request.', 'multisite-content-sync' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function arguments(): array {
		return array(
			'source_site_uuid' => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 36,
				'maxLength' => 36,
			),
			'source_object_id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
			'post_type' => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 1,
			),
			'status' => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 1,
			),
			'title' => array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => 1000,
			),
			'content' => array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => 5242880,
			),
			'excerpt' => array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => 1048576,
			),
			'slug' => array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => 200,
			),
			'date_gmt' => array(
				'type' => array( 'string', 'null' ),
			),
			'taxonomies' => array(
				'type'     => 'object',
				'required' => true,
			),
			'featured_image' => array(
				'type' => array( 'object', 'null' ),
			),
			'media' => array(
				'type'     => 'array',
				'required' => true,
				'maxItems' => 50,
				'items'    => array( 'type' => 'object' ),
			),
			'source_hash' => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 64,
				'maxLength' => 64,
			),
			'force' => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}
}
