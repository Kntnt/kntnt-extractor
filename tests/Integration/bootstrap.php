<?php
/**
 * Integration-suite bootstrap, run inside a WordPress Playground request.
 *
 * Boots a real WordPress, activates the plugin under test, then runs every
 * `*-test.php` in this directory against the live stack. Each assertion is
 * emitted in TAP format both to stdout and to a mounted report file, and any
 * failure makes the process exit non-zero so the runner's gate turns red.
 *
 * This file is not autoloaded and not part of the shipped plugin; it exists
 * only to be required by the Playground blueprint.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Boot a full WordPress request so tests exercise the real REST and plugin
// stack rather than mocks.
require '/wordpress/wp-load.php';

// The (de)activation helpers live in an admin-only include a front-end request
// never loads; the suite needs them to activate the plugin and to test its
// activation lifecycle.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Run as an anonymous visitor: the status endpoint is unauthenticated, and a
// stray logged-in user would mask a missing authentication gate.
wp_set_current_user( 0 );

// Activate the plugin under test so its hooks are registered before any test
// dispatches a request.
activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );

// Shared TAP state. The report path is the mounted host directory when present,
// so a passing run leaves visible evidence on the host even though Playground
// swallows a successful step's stdout.
$GLOBALS['kntnt_extractor_tests'] = [
	'count'  => 0,
	'failed' => 0,
	'report' => is_dir( '/results' ) ? '/results/tap.txt' : null,
];

/**
 * Records one assertion in TAP format, to stdout and the report file.
 *
 * @since 0.1.0
 *
 * @param bool   $passed      Whether the assertion held.
 * @param string $description Human-readable name of the check.
 * @return void
 */
function kntnt_extractor_assert( bool $passed, string $description ): void {

	// Count the assertion and remember any failure for the exit status.
	++$GLOBALS['kntnt_extractor_tests']['count'];
	if ( ! $passed ) {
		++$GLOBALS['kntnt_extractor_tests']['failed'];
	}

	// Emit the TAP line to stdout and, when mounted, append it to the report.
	$line = sprintf( "%s %d - %s\n", $passed ? 'ok' : 'not ok', $GLOBALS['kntnt_extractor_tests']['count'], $description );
	echo $line;
	if ( $GLOBALS['kntnt_extractor_tests']['report'] !== null ) {
		file_put_contents( $GLOBALS['kntnt_extractor_tests']['report'], $line, FILE_APPEND );
	}

}

// Run every test file in this directory. A later issue adds a `*-test.php` here
// and it is picked up with no wiring change.
foreach ( glob( __DIR__ . '/*-test.php' ) ?: [] as $test_file ) {
	require $test_file;
}

// Emit the TAP plan line summarising how many assertions ran.
$plan = sprintf( "1..%d\n", $GLOBALS['kntnt_extractor_tests']['count'] );
echo $plan;
if ( $GLOBALS['kntnt_extractor_tests']['report'] !== null ) {
	file_put_contents( $GLOBALS['kntnt_extractor_tests']['report'], $plan, FILE_APPEND );
}

// Fail the process – and therefore the gate – when any assertion failed.
if ( $GLOBALS['kntnt_extractor_tests']['failed'] > 0 ) {
	exit( 1 );
}
