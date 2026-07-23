<?php
/**
 * Sanitized structured database logger.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\Persistence;

use SalmanButt\Multisite_Content_Sync\Contracts\ActivityLogger;
use wpdb;

final readonly class WpdbActivityLogger implements ActivityLogger {
	private const SENSITIVE_KEYS = array(
		'authorization',
		'application_password',
		'credential',
		'password',
		'secret',
		'token',
	);

	public function __construct( private wpdb $database ) {}

	public function log(
		string $level,
		string $event,
		string $message,
		array $context = array(),
		?string $request_uuid = null,
	): void {
		$encoded_context = wp_json_encode(
			$this->sanitize_context( $context ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		);

		$this->database->insert(
			$this->table(),
			array(
				'level'        => sanitize_key( $level ),
				'event'        => sanitize_key( $event ),
				'message'      => substr( wp_strip_all_tags( $message ), 0, 2000 ),
				'context'      => false === $encoded_context ? '{}' : $encoded_context,
				'request_uuid' => $request_uuid,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param array<string, mixed> $context Context to sanitize.
	 * @return array<string, mixed>
	 */
	private function sanitize_context( array $context ): array {
		$sanitized = array();

		foreach ( $context as $key => $value ) {
			$normalized_key = strtolower( (string) $key );

			if ( $this->is_sensitive( $normalized_key ) ) {
				$sanitized[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_context( $value );
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$sanitized[ $key ] = is_string( $value )
					? substr( wp_strip_all_tags( $value ), 0, 1000 )
					: $value;
			}
		}

		return $sanitized;
	}

	private function is_sensitive( string $key ): bool {
		foreach ( self::SENSITIVE_KEYS as $sensitive_key ) {
			if ( str_contains( $key, $sensitive_key ) ) {
				return true;
			}
		}

		return false;
	}

	private function table(): string {
		return $this->database->prefix . 'mcs_logs';
	}
}
