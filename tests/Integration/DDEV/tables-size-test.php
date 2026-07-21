<?php
/**
 * MySQL-backed check that GET /tables' engine statistics are real, not zero.
 *
 * Run inside a booted WordPress by `wp eval-file` (see run.sh), against a real
 * MySQL/InnoDB database. It exists because the fast Playground suite runs on
 * SQLite, whose `SHOW TABLE STATUS` translation stubs `Rows`, `Data_length`, and
 * `Index_length` to zero — so the row-count and byte-size estimates that AC5
 * requires cannot be exercised there. This is the standard's DDEV fallback for
 * MySQL-specific SQL (agents.d/coding-standard/wordpress.md): it asserts that a
 * populated InnoDB table reports a positive byte-size estimate and a positive
 * estimated row count, which is exactly what the SQLite harness cannot show.
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
$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/tables' ) );
$assert( $response->get_status() === 200, 'GET /tables responds 200 to an authorized caller (MySQL)' );

// Index the listing by table name for lookups below.
$data = $response->get_data();
$tables = is_array( $data ) && isset( $data['tables'] ) && is_array( $data['tables'] ) ? $data['tables'] : [];
$by_name = [];
foreach ( $tables as $table ) {
	if ( is_array( $table ) && isset( $table['name'] ) && is_string( $table['name'] ) ) {
		$by_name[ $table['name'] ] = $table;
	}
}

// The byte-size estimate is real on MySQL: a populated InnoDB table always
// reports a positive Data_length + Index_length. This is the AC5 size-estimate
// coverage the SQLite harness cannot provide, where every byte figure is zero.
$assert( isset( $by_name[ $wpdb->options ] ) && $by_name[ $wpdb->options ]['bytes'] > 0, 'The options table reports a positive byte-size estimate' );
$assert( isset( $by_name[ $wpdb->users ] ) && $by_name[ $wpdb->users ]['bytes'] > 0, 'The users table reports a positive byte-size estimate' );
$assert( isset( $by_name[ $wpdb->posts ] ) && $by_name[ $wpdb->posts ]['bytes'] > 0, 'The posts table reports a positive byte-size estimate' );

// The row-count estimate is real on MySQL too: SHOW TABLE STATUS.Rows is a
// positive approximation for a non-empty table — the O(1) estimate that replaced
// the per-table COUNT(*) — where SQLite would again report zero.
$assert( isset( $by_name[ $wpdb->options ] ) && $by_name[ $wpdb->options ]['rows'] > 0, 'The options table reports a positive estimated row count' );
$assert( isset( $by_name[ $wpdb->posts ] ) && $by_name[ $wpdb->posts ]['rows'] > 0, 'The posts table reports a positive estimated row count' );

// Leave no authenticated user behind, then fail the process on any failed check.
wp_set_current_user( 0 );
if ( $failed > 0 ) {
	fwrite( STDERR, sprintf( "DDEV tables size/row estimate check: %d assertion(s) failed\n", $failed ) );
	exit( 1 );
}
echo "DDEV tables size/row estimate check: PASS\n";
