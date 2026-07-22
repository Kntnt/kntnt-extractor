<?php
/**
 * MySQL-backed check that GET /environment reports real runtime & DB facts.
 *
 * Run inside a booted WordPress by `wp eval-file` (see run.sh), against a real
 * MySQL/MariaDB database. It exists because the fast Playground suite runs on
 * SQLite, which cannot report a MySQL-family @@version_comment/VERSION() or
 * @@collation_database, and whose PHP version is the WASM build's, not the
 * container's — so AC5 (real database.server/version/collation and php_version)
 * cannot be exercised there. This is the standard's DDEV fallback for
 * MySQL-specific facts (agents.d/coding-standard/wordpress.md): it asserts that
 * database.server is a known flavour, database.version and collation are
 * non-empty and plausible, and php_version matches the container's running PHP.
 *
 * Prints a TAP-style line per assertion and exits non-zero if any fails, so the
 * runner turns red on failure.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
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

// The database flavour is one of the two known engines, and it matches what the
// server itself reports through VERSION() — the assertion the SQLite harness
// cannot make because SQLite reports neither.
$version_string = (string) $wpdb->get_var( 'SELECT VERSION()' );
$expected_server = stripos( $version_string, 'mariadb' ) !== false ? 'mariadb' : 'mysql';
$assert( ( $db['server'] ?? null ) === $expected_server, sprintf( 'database.server is "%s", matching the container engine', $expected_server ) );

// The reported version is a non-empty dotted version that prefixes the raw
// VERSION() string (which carries a "-MariaDB" suffix on MariaDB).
$reported_version = (string) ( $db['version'] ?? '' );
$assert( $reported_version !== '' && preg_match( '/^\d+\.\d+/', $reported_version ) === 1, 'database.version is a non-empty dotted version' );
$assert( str_starts_with( $version_string, $reported_version ), 'database.version matches the server VERSION() major.minor.patch' );

// The default collation is non-empty and matches @@collation_database.
$expected_collation = (string) $wpdb->get_var( 'SELECT @@collation_database' );
$assert( ( $db['collation'] ?? null ) === $expected_collation && $expected_collation !== '', 'database.collation matches @@collation_database' );

// The PHP version is the container's running PHP, not a WASM build's.
$assert( ( $data['php_version'] ?? null ) === PHP_VERSION, 'php_version matches the container PHP_VERSION' );

// Leave no authenticated user behind, then fail the process on any failed check.
wp_set_current_user( 0 );
if ( $failed > 0 ) {
	fwrite( STDERR, sprintf( "DDEV environment facts check: %d assertion(s) failed\n", $failed ) );
	exit( 1 );
}
echo "DDEV environment facts check: PASS\n";
