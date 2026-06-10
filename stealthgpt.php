<?php
/**
 * Plugin Name:       StealthGPT
 * Plugin URI:        https://www.stealthgpt.ai
 * Description:       Generate and humanize natural, human, publish-ready content with the StealthGPT API. Output is always saved to a draft for your review.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            XYZ AI LLC
 * Author URI:        https://www.stealthgpt.ai
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stealthgpt
 * Domain Path:       /languages
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STEALTHGPT_VERSION', '1.0.0' );
define( 'STEALTHGPT_PLUGIN_FILE', __FILE__ );
define( 'STEALTHGPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STEALTHGPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'STEALTHGPT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Composer autoloader for bundled libraries (CommonMark, Action Scheduler).
 *
 * The plugin still works for core start/draft flows without Composer deps, but
 * Markdown conversion and the polling fallback require the vendor directory.
 */
$stealthgpt_autoload = STEALTHGPT_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $stealthgpt_autoload ) ) {
	require_once $stealthgpt_autoload;
}

/*
 * Action Scheduler must be loaded from the main plugin file, before the
 * `plugins_loaded` hook fires: it registers itself on `plugins_loaded` at
 * priorities 0/1 and never initializes if required from within that hook.
 */
$stealthgpt_as_loader = STEALTHGPT_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( is_readable( $stealthgpt_as_loader ) ) {
	require_once $stealthgpt_as_loader;
}

/**
 * Lightweight autoloader for the plugin's own classes.
 *
 * Maps StealthGPT_Foo_Bar -> includes/class-stealthgpt-foo-bar.php and
 * admin classes to admin/class-stealthgpt-*.php.
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'StealthGPT_' ) ) {
			return;
		}

		$slug = strtolower( str_replace( '_', '-', $class_name ) );
		$file = 'class-' . $slug . '.php';

		$candidates = array(
			STEALTHGPT_PLUGIN_DIR . 'includes/' . $file,
			STEALTHGPT_PLUGIN_DIR . 'admin/' . $file,
		);

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'StealthGPT_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'StealthGPT_Plugin', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded.
 */
function stealthgpt_bootstrap() {
	StealthGPT_Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'stealthgpt_bootstrap' );
