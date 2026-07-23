<?php
/**
 * Runtime requirements.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Support;

final class Requirements {
	public const MINIMUM_PHP       = '8.3';
	public const MINIMUM_WORDPRESS = '7.0';

	public static function is_satisfied(): bool {
		global $wp_version;

		return version_compare( PHP_VERSION, self::MINIMUM_PHP, '>=' )
			&& version_compare( (string) $wp_version, self::MINIMUM_WORDPRESS, '>=' )
			&& extension_loaded( 'openssl' );
	}

	public static function assert_for_activation(): void {
		if ( self::is_satisfied() ) {
			return;
		}

		deactivate_plugins( MCS_BASENAME );

		wp_die(
			esc_html( self::message() ),
			esc_html__( 'Multisite Content Sync requirements not met', 'multisite-content-sync' ),
			array( 'back_link' => true )
		);
	}

	public static function render_admin_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( self::message() )
		);
	}

	private static function message(): string {
		return sprintf(
			/* translators: 1: PHP version, 2: WordPress version. */
			__( 'Multisite Content Sync requires PHP %1$s+, WordPress %2$s+, and the OpenSSL extension.', 'multisite-content-sync' ),
			self::MINIMUM_PHP,
			self::MINIMUM_WORDPRESS,
		);
	}
}
