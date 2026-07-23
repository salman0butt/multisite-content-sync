<?php
/**
 * Plugin Name:       Multisite Content Sync
 * Plugin URI:        https://github.com/salman0butt/multisite-content-sync
 * Description:       Securely synchronize WordPress content across independent sites.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            Salman Butt
 * Author URI:        https://github.com/salman0butt
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       multisite-content-sync
 * Domain Path:       /languages
 */

declare(strict_types=1);

use SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress\Activator;
use SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress\Deactivator;
use SalmanButt\Multisite_Content_Sync\Plugin;
use SalmanButt\Multisite_Content_Sync\Support\Requirements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCS_VERSION', '0.1.0' );
define( 'MCS_FILE', __FILE__ );
define( 'MCS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MCS_URL', plugin_dir_url( __FILE__ ) );
define( 'MCS_BASENAME', plugin_basename( __FILE__ ) );

$composer_autoloader = MCS_PATH . 'vendor/autoload.php';

if ( is_readable( $composer_autoloader ) ) {
	require_once $composer_autoloader;
} else {
	require_once MCS_PATH . 'src/Support/Autoloader.php';
	\SalmanButt\Multisite_Content_Sync\Support\Autoloader::register();
}

register_activation_hook( MCS_FILE, array( Activator::class, 'activate' ) );
register_deactivation_hook( MCS_FILE, array( Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! Requirements::is_satisfied() ) {
			add_action( 'admin_notices', array( Requirements::class, 'render_admin_notice' ) );
			return;
		}

		( new Plugin() )->boot();
	}
);
