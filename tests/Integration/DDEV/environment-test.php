<?php
/**
 * MySQL-backed check that GET /environment reports real runtime & DB facts.
 *
 * Run inside a booted WordPress by `wp eval-file` (see run.sh), against a real
 * MySQL/MariaDB database. It exists because the fast Playground suite runs on
 * SQLite, which cannot report a MySQL-family server version, flavour, or default
 * collation — exactly as tables-size-test.php notes for SHOW TABLE STATUS. This
 * is the standard's DDEV fallback (agents.d/coding-standard/wordpress.md) for
 * facts only a real engine can supply: it asserts that database.server is one of
 * the two known flavours, that database.version is a plausible engine version,
 * that database.collation is non-empty, and that php_version matches the
 * container's running PHP — none of which the SQLite harness can exercise.
 *
 * Prints a TAP-style line per assertion and exits non-zero if any fails, so the
 * runner turns red on failure.
 *
 * @package Kntnt\Extractor
 * @since   0.2.0
 */

declare( strict_types = 1 );

/**
 * The WordPress database access layer.
 *
 * @var \wpdb $wpdb
 */
global $wpdb;

// Minimal TAP assertion helper: record each check and remember any failure so
// the process can exit non-zero for the runner.
$failed = 0;
$assert = static function ( bool $passed, string $description ) use ( &$failed ): void {
	printf( "%s - %s\n", $passed ? 'ok' : 'not ok', $description );
	if ( ! $passed ) {
		++$failed;
	}
};

// Authorize as the administrator, who holds both the Operate capability and
// manage_options after activation, then dispatch the real endpoint.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $admin->ID );
$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/environment' ) );
$assert( $response->get_status() === 200, 'GET /environment responds 200 to an authorized caller (MySQL)' );

$data = $response->get_data();
$data = is_array( $data ) ? $data : [];
$db = is_array( $data['database'] ?? null ) ? $data['database'] : [];

// The database flavour is one of the two known engines, derived from a real
// @@version_comment / VERSION() that the SQLite harness cannot answer.
$assert( in_array( $db['server'] ?? null, [ 'mysql', 'mariadb' ], true ), 'database.server is a real MySQL-family flavour (mysql|mariadb)' );

// The reported flavour agrees with the engine's own version string.
$version_string = (string) $wpdb->get_var( 'SELECT VERSION()' );
$expected_server = stripos( $version_string, 'mariadb' ) !== false ? 'mariadb' : 'mysql';
$assert( ( $db['server'] ?? null ) === $expected_server, 'database.server agrees with the engine VERSION() string' );

// The version is a plausible major.minor engine version, not empty or a stub.
$assert( is_string( $db['version'] ?? null ) && (bool) preg_match( '/^\d+\.\d+/', (string) ( $db['version'] ?? '' ) ), 'database.version is a plausible engine version' );

// The default collation is a real, non-empty collation name.
$assert( is_string( $db['collation'] ?? null ) && ( $db['collation'] ?? '' ) !== '', 'database.collation is a non-empty collation name' );

// php_version matches the container's running PHP: this eval-file runs in the
// same PHP process the endpoint answered in, so the two must agree exactly.
$assert( ( $data['php_version'] ?? null ) === PHP_VERSION, 'php_version matches the container PHP version' );

// Leave no authenticated user behind, then fail the process on any failed check.
wp_set_current_user( 0 );
if ( $failed > 0 ) {
	fwrite( STDERR, sprintf( "DDEV environment facts check: %d assertion(s) failed\n", $failed ) );
	exit( 1 );
}
echo "DDEV environment facts check: PASS\n";
