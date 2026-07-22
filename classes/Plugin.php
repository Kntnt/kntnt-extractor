<?php
/**
 * Plugin singleton – bootstrap and hook wiring.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use Kntnt\Extractor\Rest\Audit_Log_Controller;
use Kntnt\Extractor\Rest\Environment_Controller;
use Kntnt\Extractor\Rest\Extractions_Controller;
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
		// seam the file Manifest reads its page size through and the Job_Store reads
		// its working-directory location through. The Job_Store persists Extraction
		// jobs as state files under the working directory (ADR-0004/0008). The
		// Dispatcher drives a queued job to a sealed artifact through the
		// Artifact_Builder and its Table_Dumper, one secret-authenticated tick at a
		// time (ADR-0007/0009). The Sweeper is the TTL backstop that reclaims a
		// never-consumed job (ADR-0004); it answers the recurring cron event the
		// Installer schedules against Sweeper::SWEEP_HOOK. The Watchdog is the stall
		// backstop that restarts a queue whose loopback died (ADR-0007); it answers its
		// own recurring event against Watchdog::WATCHDOG_HOOK. The Audit_Log records every
		// completed extraction at the ready transition — the non-evadable trigger — and
		// answers the administrator-only GET /audit-log (ADR-0006).
		$authorizer = new Authorizer();
		$config = new Config();
		$job_store = new Job_Store( $config );
		$dispatcher = new Dispatcher( $job_store, $config, new Artifact_Builder( new Table_Dumper(), $config ) );
		$sweeper = new Sweeper( $job_store, $config );
		$watchdog = new Watchdog( $job_store, $dispatcher );
		$audit_log = new Audit_Log();
		$status_controller = new Status_Controller();
		$tables_controller = new Tables_Controller( $authorizer );
		$environment_controller = new Environment_Controller( $authorizer, $config );
		$files_controller = new Files_Controller( $authorizer, $config );
		$extractions_controller = new Extractions_Controller( $authorizer, $config, $job_store, $dispatcher );
		$audit_log_controller = new Audit_Log_Controller( $audit_log );
		add_action( 'rest_api_init', $status_controller->register_routes( ... ) );
		add_action( 'rest_api_init', $tables_controller->register_routes( ... ) );
		add_action( 'rest_api_init', $environment_controller->register_routes( ... ) );
		add_action( 'rest_api_init', $files_controller->register_routes( ... ) );
		add_action( 'rest_api_init', $extractions_controller->register_routes( ... ) );
		add_action( 'rest_api_init', $audit_log_controller->register_routes( ... ) );
		add_action( Sweeper::SWEEP_HOOK, $sweeper->run( ... ) );

		// Drive the job unattended (ADR-0007). The self-dispatching loopback on create
		// and after each chunk is the primary driver, wired in the Dispatcher and the
		// extractions controller; the Watchdog is the backstop that restarts a queue
		// whose loopback died. Its recurring patrol answers the cron event the Installer
		// schedules against Watchdog::WATCHDOG_HOOK, and its sub-hourly schedule is
		// contributed to WordPress's cron intervals so that event has a recurrence to
		// bind to at activation time.
		add_filter( 'cron_schedules', $watchdog->register_schedule( ... ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- the interval is declared as a 15-minute constant in Watchdog::register_schedule(); the sniff cannot follow the first-class-callable reference to read it.
		add_action( Watchdog::WATCHDOG_HOOK, $watchdog->run( ... ) );

		// Record every completed extraction the moment it reaches ready — the
		// sanctioned, non-evadable trigger, never at consume (ADR-0004/0006).
		add_action( 'kntnt_extractor_job_ready', $audit_log->record( ... ) );

		// Register the self-hosted update checker so a new GitHub release shows on
		// the Plugins screen and installs in place (ADR-0005). It is independent of
		// the capability-gated REST surface and runs on every load, keeping the
		// "install and forget" promise regardless of the plugin's dormancy.
		( new Update_Checker( self::$plugin_file ) )->register();

	}

}
