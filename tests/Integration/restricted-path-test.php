<?php
/**
 * Integration test: the credential-bearing restricted-path deny-list (ADR-0011).
 *
 * Two concerns: `Restricted_Path::is_restricted()` is exercised directly with a
 * positive and a negative case for every pattern class the deny-list defines
 * (AC1), and `POST /extractions` is proven to reject a selection naming a
 * restricted path with a 422 that names every offending path, decided before
 * both the existence check and the capability gate — so an unauthorized caller
 * still sees the 422, never a 403 or a 404 (AC2/AC3). `wp-config-sample.php` is
 * proven to pass the check, and `GET /files` is proven unaffected: a restricted
 * path stays listed, unannotated (AC4).
 *
 * @package Kntnt\Extractor
 * @since   0.3.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Restricted_Path;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

// --- AC1: pattern-by-pattern positive and negative cases, direct and isolated ---

// wp-config.php and its siblings, matched anywhere in the tree.
kntnt_extractor_assert( Restricted_Path::is_restricted( 'wp-config.php' ), 'wp-config.php itself is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'WP-CONFIG.PHP' ), 'The match is case-insensitive' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'wp-config.php.bak-20260717-212309' ), 'A dated .bak sibling is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'wp-config.php.save' ), 'A .save sibling is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'wp-config.php~' ), 'An editor tilde swap file is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'wp-config-old.php' ), 'A wp-config-*.php variant is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'backups/wp-config.php.bak' ), 'A wp-config backup nested in a subdirectory is still restricted' );
kntnt_extractor_assert( ! Restricted_Path::is_restricted( 'wp-config-sample.php' ), 'wp-config-sample.php is explicitly not restricted' );
kntnt_extractor_assert( ! Restricted_Path::is_restricted( 'not-wp-config.php' ), 'A file merely containing the name is not restricted' );

// .env and its siblings, matched anywhere in the tree.
kntnt_extractor_assert( Restricted_Path::is_restricted( '.env' ), '.env is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( '.env.local' ), '.env.local is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'app/config/.env.production' ), 'A nested .env variant is restricted' );
kntnt_extractor_assert( ! Restricted_Path::is_restricted( 'environment.php' ), 'A file merely starting with "env" is not restricted' );
kntnt_extractor_assert( ! Restricted_Path::is_restricted( 'notes.env.txt' ), 'A file that does not begin with .env is not restricted' );

// Database dumps and key material, restricted only directly in the install root.
kntnt_extractor_assert( Restricted_Path::is_restricted( 'dump.sql' ), 'A root-level .sql dump is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'dump.sql.gz' ), 'A root-level .sql.gz dump is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'dump.sql.zip' ), 'A root-level .sql.zip dump is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'server.pem' ), 'A root-level .pem file is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'id_rsa' ), 'A root-level id_rsa key is restricted' );
kntnt_extractor_assert( Restricted_Path::is_restricted( 'id_rsa.pub' ), 'A root-level id_rsa.pub key is restricted' );
kntnt_extractor_assert( ! Restricted_Path::is_restricted( 'wp-content/backups/dump.sql' ), 'The same dump nested below the root is not restricted' );
kntnt_extractor_assert( ! Restricted_Path::is_restricted( 'notes.sql.txt' ), 'A file that does not end in .sql is not restricted' );

// AC1: matches() reports every offending path, in selection order.
kntnt_extractor_assert(
	Restricted_Path::matches( [ 'wp-load.php', 'wp-config.php.bak', '.env', 'wp-config-sample.php' ] ) === [ 'wp-config.php.bak', '.env' ],
	'matches() returns every restricted path in the selection, skipping the rest'
);
kntnt_extractor_assert( Restricted_Path::matches( [ 'wp-load.php' ] ) === [], 'matches() is empty when nothing in the selection is restricted' );

// --- AC2/AC3: POST /extractions rejects a restricted selection before existence and the gate ---

/**
 * Dispatches POST /extractions with a JSON body through the live REST server.
 *
 * @param array<string, mixed> $body Body to send, JSON-encoded.
 * @return WP_REST_Response
 */
$post_extractions = static function ( array $body ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $request );
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// Isolate the working directory so this file's rejected creates can be proven to
// have left nothing behind, independent of any other suite file's state.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-test-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

$valid_key = base64_encode( random_bytes( 32 ) );

// Run every ladder check as an unauthorized (anonymous) caller: a 422 here proves
// the restricted-path rejection precedes the capability gate that would otherwise
// answer 403.
wp_set_current_user( 0 );

$single = $post_extractions( [ 'files' => [ 'wp-config.php.bak-test' ], 'public_key' => $valid_key ] );
$single_data = $single->get_data();
kntnt_extractor_assert( $single->get_status() === 422, 'A selection naming a restricted path is rejected 422 (AC2)' );
kntnt_extractor_assert( is_array( $single_data ) && ( $single_data['code'] ?? null ) === 'kntnt_extractor_restricted_path', 'The rejection carries the dedicated kntnt_extractor_restricted_path code, not the generic unknown-resource 404' );
kntnt_extractor_assert( is_array( $single_data ) && is_array( $single_data['data'] ?? null ) && ( $single_data['data']['paths'] ?? null ) === [ 'wp-config.php.bak-test' ], 'The error data names the offending path' );

// Every offending path is named, not merely the first.
$multi = $post_extractions( [ 'files' => [ 'wp-config.php.bak-test', '.env' ], 'public_key' => $valid_key ] );
$multi_data = $multi->get_data();
kntnt_extractor_assert( $multi->get_status() === 422, 'A selection naming several restricted paths is rejected 422' );
kntnt_extractor_assert( is_array( $multi_data ) && is_array( $multi_data['data'] ?? null ) && ( $multi_data['data']['paths'] ?? null ) === [ 'wp-config.php.bak-test', '.env' ], 'The error data names every offending path (AC2)' );

// AC3: the restricted-path check precedes the existence check — combined with an
// unknown table, the response is still the restricted-path 422, never the 404 an
// unknown table alone would earn.
$combined = $post_extractions( [ 'tables' => [ 'wp_no_such_table_xyz' ], 'files' => [ 'wp-config.php.bak-test' ], 'public_key' => $valid_key ] );
$combined_data = $combined->get_data();
kntnt_extractor_assert( $combined->get_status() === 422 && is_array( $combined_data ) && ( $combined_data['code'] ?? null ) === 'kntnt_extractor_restricted_path', 'The restricted-path 422 precedes the unknown-table 404 (AC3)' );

// wp-config-sample.php is explicitly not restricted: the request survives the
// deny-list and reaches the capability gate, which an anonymous caller fails —
// proving the negative case through the real request path, not only the direct unit check.
$sample = $post_extractions( [ 'files' => [ 'wp-config-sample.php' ], 'public_key' => $valid_key ] );
kntnt_extractor_assert( $sample->get_status() === 403, 'wp-config-sample.php clears the deny-list and reaches the capability gate (403 for this anonymous caller)' );

// No job may have been created by any of the rejected attempts above.
kntnt_extractor_assert( ! is_dir( $work ) || count( array_diff( scandir( $work ) ?: [], [ '.', '..', 'index.html', '.htaccess', 'web.config' ] ) ) === 0, 'A rejected create persists no job' );

remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
wp_set_current_user( 0 );

// --- AC4: GET /files is unaffected — a restricted path stays listed, unannotated ---

$get_files = static function ( ?string $cursor = null ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', '/kntnt-extractor/v1/files' );
	if ( $cursor !== null ) {
		$request->set_param( 'cursor', $cursor );
	}
	return rest_get_server()->dispatch( $request );
};

// Drop a real, restricted-looking file directly in the installation root and
// authenticate as the administrator, who holds both required capabilities.
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $owner->ID );
$root = (string) realpath( ABSPATH );
$fixture = $root . '/kntnt-extractor-test-' . bin2hex( random_bytes( 4 ) ) . '.sql';
file_put_contents( $fixture, 'SELECT 1;' );
$fixture_name = basename( $fixture );

// Walk every page until the fixture is found or the listing is exhausted —
// whatever page size the install runs at, this must not depend on the fixture
// landing on the first page.
$fixture_entry = null;
$cursor = null;
do {
	$files_response = $get_files( $cursor );
	kntnt_extractor_assert( $files_response->get_status() === 200, 'GET /files answers the administrator (200)' );
	$listing = $files_response->get_data();
	$entries = is_array( $listing ) && isset( $listing['files'] ) && is_array( $listing['files'] ) ? $listing['files'] : [];
	foreach ( $entries as $entry ) {
		if ( is_array( $entry ) && ( $entry['path'] ?? null ) === $fixture_name ) {
			$fixture_entry = $entry;
			break 2;
		}
	}
	$cursor = is_array( $listing ) && isset( $listing['cursor'] ) && is_string( $listing['cursor'] ) ? $listing['cursor'] : null;
} while ( $cursor !== null );
@unlink( $fixture );

kntnt_extractor_assert( $fixture_entry !== null, 'The restricted-looking fixture is still listed by GET /files (AC4)' );
kntnt_extractor_assert( is_array( $fixture_entry ) && ! array_key_exists( 'restricted', $fixture_entry ), 'The listed entry carries no "restricted" annotation — the manifest stays unfiltered and unannotated (AC4)' );

wp_set_current_user( 0 );
