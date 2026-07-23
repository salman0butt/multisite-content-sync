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

final readonly class WordPressRemoteSiteClient implements RemoteSiteClient {
	public function __construct( private CredentialCipher $cipher ) {}

	public function handshake( Connection $connection ): array {
		$endpoint = $connection->site_url . '/wp-json/mcs/v1/receiver/handshake';
		$token    = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication requires this encoding.
			$connection->username . ':' . $this->cipher->decrypt( $connection->encrypted_credential )
		);
		$response = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Basic ' . $token,
					'User-Agent'    => 'Multisite-Content-Sync/' . MCS_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = sanitize_text_field( $response->get_error_message() );
			throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are not rendered as HTML.
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = is_array( $body ) && isset( $body['message'] )
				? sanitize_text_field( (string) $body['message'] )
				: 'Remote connection failed.';
			throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are not rendered as HTML.
		}

		if ( ! is_array( $body ) || empty( $body['site_uuid'] ) ) {
			throw new RuntimeException( 'The remote site returned an invalid handshake response.' );
		}

		return $body;
	}
}
