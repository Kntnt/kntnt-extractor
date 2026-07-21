<?php
/**
 * Plugin singleton – bootstrap and hook wiring.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use Kntnt\Extractor\Rest\Files_Controller;
use Kntnt\Extractor\Rest\Status_Controller;
use Kntnt\Extractor\Rest\Tables_Controller;

/**
 * Singleton entry point for the Kntnt Extractor plugin.
 *
 * Constructed once by get_instance(), only after the main plugin file has
 * established that the runtime can support the plugin. The constructor
 * registers every WordPress hook, so it stays the single authoritative place to
 * trace the hook graph.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * The sole instance of this class.
	 *
	 * @since 0.1.0
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Returns (and on the first call, creates) the singleton instance.
	 *
	 * The first call must pass the absolute path to the main plugin file so
	 * later code can resolve URLs and headers without globals. Subsequent calls
	 * ignore the argument and return the existing instance.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plugin_file Absolute path to the main plugin file. Ignored
	 *                            on calls after the first.
	 * @return self
	 */
	public static function get_instance( string $plugin_file ): self {

		// Return early when already bootstrapped.
		if ( self::$instance !== null ) {
			return self::$instance;
		}

		// Capture the plugin file path and initialise the singleton.
		self::$plugin_file = $plugin_file;
		self::$instance = new self();

		return self::$instance;

	}

	/**
	 * Returns the absolute path to the main plugin file.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to the main plugin file.
	 */
	public static function get_plugin_file(): string {
		return self::$plugin_file;
	}

	/**
	 * Registers the plugin's WordPress hooks.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {

		// Register the REST controllers on rest_api_init, the one point where
		// WordPress guarantees the REST server exists and routes may be added.
		// The Authorizer is the shared both-capabilities gate the data endpoints
		// reuse as their permission callback, and Config is the constant-then-filter
		// seam the file Manifest reads its page size through.
		$authorizer = new Authorizer();
		$config = new Config();
		$status_controller = new Status_Controller();
		$tables_controller = new Tables_Controller( $authorizer );
		$files_controller = new Files_Controller( $authorizer, $config );
		add_action( 'rest_api_init', $status_controller->register_routes( ... ) );
		add_action( 'rest_api_init', $tables_controller->register_routes( ... ) );
		add_action( 'rest_api_init', $files_controller->register_routes( ... ) );

	}

}
