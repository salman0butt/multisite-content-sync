<?php
/**
 * Minimal PSR-4 fallback autoloader.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Support;

final class Autoloader {
	private const PREFIX = 'SalmanButt\\Multisite_Content_Sync\\';

	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	private static function autoload( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( self::PREFIX ) );
		$file           = MCS_PATH . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
