<?php
/**
 * Plugin Name:       Kntnt Extractor
 * Plugin URI:        https://github.com/Kntnt/kntnt-extractor
 * Description:       Capability-gated REST API for extracting a selection of database tables and files from a site.
 * Version:           0.1.1
 * Requires at least: 6.0
 * Requires PHP:      8.4
 * Author:            Thomas Barregren
 * Author URI:        https://www.kntnt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kntnt-extractor
 * Domain Path:       /languages
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The PHP floor, mirroring the `Requires PHP` header above. WordPress reads the
// header, but PHP itself cannot, so the guard below needs its own copy.
const KNTNT_EXTRACTOR_MINIMUM_PHP = '8.4';

/**
 * Guards against running on a PHP version older than the declared floor.
 *
 * The plugin header already makes WordPress block activation on older installs.
 * This is a second line of defence for environments that load the plugin
 * outside the normal activation path: it shows an admin notice and deactivates
 * the plugin so it never reaches code that would fatally error on older syntax.
 *
 * @since 0.1.0
 *
 * @return bool True when PHP meets the floor; false when the guard fires.
 */
function kntnt_extractor_requirements_check(): bool {

	// Nothing to do when the runtime meets the requirement.
	if ( version_compare( PHP_VERSION, KNTNT_EXTRACTOR_MINIMUM_PHP, '>=' ) ) {
		return true;
	}

	// Surface the problem as an admin notice.
	add_action(
		'admin_notices',
		static function (): void {
			$message = sprintf(
				/* translators: 1: required PHP version, 2: current version. */
				__( 'Kntnt Extractor requires PHP %1$s or later. This server runs PHP %2$s. The plugin has been deactivated.', 'kntnt-extractor' ),
				KNTNT_EXTRACTOR_MINIMUM_PHP,
				PHP_VERSION,
			);
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		},
	);

	// Deactivate the plugin so WordPress does not try to load it again.
	add_action(
		'admin_init',
		static function (): void {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		},
	);

	return false;

}

// Abort before loading anything else if the runtime cannot support the plugin.
if ( ! kntnt_extractor_requirements_check() ) {
	return;
}

// Load the PSR-4 autoloader for the plugin's own classes.
require_once __DIR__ . '/autoloader.php';

// Switch the plugin's dormancy on and off: activation grants the Operate
// capability to the administrator role and reactivation re-runs the grant
// (self-healing), while deactivation revokes it.
register_activation_hook( __FILE__, [ \Kntnt\Extractor\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Kntnt\Extractor\Installer::class, 'deactivate' ] );

// Bootstrap the plugin singleton, which registers every WordPress hook.
\Kntnt\Extractor\Plugin::get_instance( __FILE__ );
