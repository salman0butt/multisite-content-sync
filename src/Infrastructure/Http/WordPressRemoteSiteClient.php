<?php
/**
 * WordPress HTTP API remote client.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\Http;

use RuntimeException;
use SalmanButt\Multisite_Content_Sync\Contracts\CredentialCipher;
use SalmanButt\Multisite_Content_Sync\Contracts\RemoteSiteClient;
use SalmanButt\Multisite_Content_Sync\Domain\Connection\Connection;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\RemoteSyncException;
use WP_Error;

final readonly class WordPressRemoteSiteClient implements RemoteSiteClient {
	public function __construct( private CredentialCipher $cipher ) {}

	public function handshake( Connection $connection ): array {
		$result = $this->request( $connection, 'GET', '/receiver/handshake' );

		if (
			! isset( $result['site_uuid'], $result['plugin_version'] )
			|| ! is_string( $result['site_uuid'] )
			|| ! $this->is_uuid( $result['site_uuid'] )
		) {
			throw new RemoteSyncException( 'The remote site returned an invalid handshake response.', 502 );
		}

		return $result;
	}

	public function sync_content( Connection $connection, array $payload, bool $force = false ): array {
		$payload['force'] = $force;

		return $this->request( $connection, 'POST', '/receiver/content', $payload );
	}

	/**
	 * @param array<string, mixed>|null $payload JSON request body.
	 * @return array<string, mixed>
	 */
	private function request(
		Connection $connection,
		string $method,
		string $path,
		?array $payload = null,
	): array {
		$endpoint = $connection->site_url . '/wp-json/mcs/v1' . $path;
		$token    = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication requires this encoding.
			$connection->username . ':' . $this->cipher->decrypt( $connection->encrypted_credential )
		);
		$args     = array(
			'timeout'             => 30,
			'redirection'         => 3,
			'limit_response_size' => 2097152,
			'headers'             => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . $token,
				'User-Agent'    => 'Multisite-Content-Sync/' . MCS_VERSION,
			),
		);

		if ( null !== $payload ) {
			$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( false === $body ) {
				throw new RuntimeException( 'Unable to encode the remote request.' );
			}

			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $body;
			$args['data_format']             = 'body';
		}

		$response = 'GET' === $method
			? wp_safe_remote_get( $endpoint, $args )
			: wp_safe_remote_post( $endpoint, $args );

		if ( $response instanceof WP_Error ) {
			throw new RemoteSyncException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is not output directly.
				'The destination site could not be reached.',
				0,
				'mcs_transport_error',
				array(),
				new RuntimeException( sanitize_text_field( $response->get_error_message() ) ),
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$body        = json_decode( $raw_body, true );

		if ( ! is_array( $body ) ) {
			throw new RemoteSyncException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status code is internal exception context.
				'The destination returned invalid JSON.',
				$status_code,
			);
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			$code    = isset( $body['code'] ) ? sanitize_key( (string) $body['code'] ) : 'mcs_remote_error';
			$message = isset( $body['message'] )
				? sanitize_text_field( (string) $body['message'] )
				: 'The destination rejected the synchronization request.';
			$details = isset( $body['data'] ) && is_array( $body['data'] )
				? $this->safe_error_details( $body['data'] )
				: array();

			throw new RemoteSyncException( // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Values are sanitized remote exception context.
				$message,
				$status_code,
				$code,
				$details,
			);
		}

		return $body;
	}

	/**
	 * @param array<string, mixed> $details Remote REST error data.
	 * @return array<string, mixed>
	 */
	private function safe_error_details( array $details ): array {
		$safe = array();

		foreach ( array( 'destination_object_id', 'destination_hash', 'status' ) as $key ) {
			if ( array_key_exists( $key, $details ) && ( is_scalar( $details[ $key ] ) || null === $details[ $key ] ) ) {
				$safe[ $key ] = $details[ $key ];
			}
		}

		return $safe;
	}

	private function is_uuid( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}
}
