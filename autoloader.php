<?php
/**
 * PSR-4 autoloader for the plugin's own namespace.
 *
 * Hand-written rather than delegating to `vendor/autoload.php`: the plugin has
 * no runtime dependency, so Composer is a development-only tool here and
 * `vendor/` is neither shipped nor committed. Loading classes through it would
 * make the plugin fatal on any install that never ran `composer install`.
 *
 * Maps `Kntnt\Extractor\<Class_Name>` to `classes/<Class_Name>.php`, with
 * sub-namespaces as sub-directories.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	/**
	 * Loads a class from `classes/` when it belongs to this plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param string $class_name Fully qualified class name being resolved.
	 * @return void
	 */
	static function ( string $class_name ): void {

		// Ignore every class outside the plugin's namespace – other autoloaders
		// own those, and this one must not answer for them.
		$prefix = 'Kntnt\\Extractor\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		// Translate the namespace-relative name into a path under classes/.
		$relative = substr( $class_name, strlen( $prefix ) );
		$path = __DIR__ . '/classes/' . str_replace( '\\', '/', $relative ) . '.php';

		// A missing file means a typo or a stale reference; let PHP raise its
		// own "class not found" error rather than masking it with a warning here.
		if ( is_readable( $path ) ) {
			require_once $path;
		}

	},
);
