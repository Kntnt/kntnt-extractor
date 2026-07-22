<?php
/**
 * Integration test: GET /environment returns read-only site & runtime facts to
 * an authorized caller, behind the shared both-capabilities Authorizer.
 *
 * This harness exercises the REST wiring end to end against the live Playground
 * server: the both-capabilities gate (AC2), the response shape (AC1), the
 * secret-family redaction of resolved wp-config defines (AC3), and the
 * root-relative content/uploads paths (AC4). The real database flavour/version
 * and PHP version (AC5) cannot be asserted here — Playground runs on SQLite,
 * which cannot report a MySQL-family @@version_comment/VERSION() — so those live
 * in the DDEV/MySQL harness (tests/Integration/DDEV/environment-mysql-test.php),
 * exactly as tables-size-test.php notes for SHOW TABLE STATUS.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

/**
 * Dispatches GET /environment through the live REST server.
 *
 * @return WP_REST_Response
 */
$get_environment = static function (): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/environment' ) );
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// The endpoint reuses the both-capabilities Authorizer (AC2): neither an
// anonymous caller nor an Operate-only caller may read it.
wp_set_current_user( 0 );
kntnt_extractor_assert( $get_environment()->get_status() === 403, 'An anonymous caller is refused GET /environment (403)' );
$operate_only = wp_insert_user( [ 'user_login' => 'kntnt_env_operate_only', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
( new WP_User( $operate_only ) )->add_cap( $operate );
wp_set_current_user( $operate_only );
kntnt_extractor_assert( $get_environment()->get_status() === 403, 'Operate without manage_options is refused GET /environment (403)' );

// Authorize as an administrator, who holds both capabilities.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $admin->ID );

// Point the controller at a fixture wp-config source carrying a secret-family
// trio plus one non-secret name, so the redaction rule (AC3) and the
// "resolve live, never echo the source expression" rule are both exercised
// deterministically rather than depending on Playground's generated config.
$fixture = wp_tempnam( 'kntnt-env-wp-config' );
file_put_contents(
	$fixture,
	"<?php\n"
	. "define( 'DB_PASSWORD', 'topsecret-password' );\n"
	. "define( \"AUTH_SALT\", 'topsecret-auth-salt' );\n"
	. "define( 'NONCE_KEY', 'topsecret-nonce-key' );\n"
	. "define( 'WP_MEMORY_LIMIT', '999M' );\n",
);
$point_config = static fn(): string => $fixture;
add_filter( 'kntnt_extractor_environment_wp_config_path', $point_config );

// Relocate uploads to a non-default subdirectory under the install root so the
// relative-path contract (AC4) is exercised against a non-default layout, not
// only the default wp-content/uploads.
$custom_uploads_rel = 'wp-content/kntnt-custom-uploads';
$relocate_uploads = static function ( array $dirs ) use ( $custom_uploads_rel ): array {
	$dirs['basedir'] = untrailingslashit( ABSPATH ) . '/' . $custom_uploads_rel;
	$dirs['baseurl'] = content_url( 'kntnt-custom-uploads' );
	$dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
	$dirs['url'] = $dirs['baseurl'] . $dirs['subdir'];
	return $dirs;
};
add_filter( 'upload_dir', $relocate_uploads );

// Seed a drop-in so the dropins list reflects real, present state (AC1).
$object_cache = untrailingslashit( WP_CONTENT_DIR ) . '/object-cache.php';
$seeded_dropin = ! file_exists( $object_cache );
if ( $seeded_dropin ) {
	file_put_contents( $object_cache, "<?php\n// Test drop-in; no behaviour.\n" );
}

// An authorized caller reads 200.
$response = $get_environment();
kntnt_extractor_assert( $response->get_status() === 200, 'A caller holding both capabilities may read GET /environment (200)' );
$data = $response->get_data();
kntnt_extractor_assert( is_array( $data ), 'GET /environment returns an object' );
$data = is_array( $data ) ? $data : [];

// AC1 — the top-level facts are all present and correctly typed.
kntnt_extractor_assert( isset( $data['php_version'] ) && is_string( $data['php_version'] ) && $data['php_version'] !== '', 'php_version is a non-empty string' );
kntnt_extractor_assert( array_key_exists( 'server_software', $data ) && is_string( $data['server_software'] ), 'server_software is a string' );
kntnt_extractor_assert( isset( $data['active_plugins'] ) && is_array( $data['active_plugins'] ), 'active_plugins is an array' );
kntnt_extractor_assert( isset( $data['dropins'] ) && is_array( $data['dropins'] ), 'dropins is an array' );
kntnt_extractor_assert( isset( $data['defines'] ) && is_array( $data['defines'] ), 'defines is an array' );

// AC1 — the wordpress facts are present and correctly typed.
$wp = is_array( $data['wordpress'] ?? null ) ? $data['wordpress'] : [];
$wp_keys = [ 'core_version', 'home_url', 'site_url', 'table_prefix', 'content_dir', 'uploads_dir' ];
$wp_ok = true;
foreach ( $wp_keys as $key ) {
	if ( ! isset( $wp[ $key ] ) || ! is_string( $wp[ $key ] ) || $wp[ $key ] === '' ) {
		$wp_ok = false;
	}
}
kntnt_extractor_assert( $wp_ok, 'wordpress carries core_version, home_url, site_url, table_prefix, content_dir, uploads_dir as non-empty strings' );

// AC1 — the database facts are present; server is one of the two known flavours.
// The real version/collation values are only assertable on a MySQL-family engine
// (AC5, DDEV harness) — here only the shape and key presence are checked.
$db = is_array( $data['database'] ?? null ) ? $data['database'] : [];
kntnt_extractor_assert( isset( $db['server'] ) && in_array( $db['server'], [ 'mysql', 'mariadb' ], true ), 'database.server is "mysql" or "mariadb"' );
kntnt_extractor_assert( array_key_exists( 'version', $db ) && ( is_string( $db['version'] ) || $db['version'] === null ), 'database.version key is present (string or null on SQLite)' );
kntnt_extractor_assert( array_key_exists( 'collation', $db ) && ( is_string( $db['collation'] ) || $db['collation'] === null ), 'database.collation key is present (string or null on SQLite)' );

// AC4 — content_dir and uploads_dir are relative to the install root: no leading
// slash and no absolute server path (the install root string) anywhere.
$root = untrailingslashit( wp_normalize_path( ABSPATH ) );
$content_dir = (string) ( $wp['content_dir'] ?? '' );
$uploads_dir = (string) ( $wp['uploads_dir'] ?? '' );
kntnt_extractor_assert( $content_dir !== '' && ! str_starts_with( $content_dir, '/' ) && ! str_contains( $content_dir, $root ), 'wordpress.content_dir is relative to the install root (no absolute path)' );
kntnt_extractor_assert( $uploads_dir !== '' && ! str_starts_with( $uploads_dir, '/' ) && ! str_contains( $uploads_dir, $root ), 'wordpress.uploads_dir is relative to the install root (no absolute path)' );
kntnt_extractor_assert( $uploads_dir === $custom_uploads_rel, 'wordpress.uploads_dir reflects a non-default uploads layout, root-relative' );

// AC1 — active_plugins reflects real state: the plugin under test is active.
kntnt_extractor_assert( in_array( 'kntnt-extractor/kntnt-extractor.php', $data['active_plugins'], true ), 'active_plugins includes the active plugin under test' );

// AC1 — the seeded drop-in is reported present.
kntnt_extractor_assert( in_array( 'object-cache.php', $data['dropins'], true ), 'dropins reflects the seeded object-cache.php drop-in' );

// AC3 — every secret-family define appears by name with a null value, and no
// secret value is ever emitted anywhere in the body.
$defines_by_name = [];
$defines_well_formed = true;
foreach ( $data['defines'] as $define ) {
	if ( ! is_array( $define ) || ! isset( $define['name'] ) || ! is_string( $define['name'] ) || ! array_key_exists( 'value', $define ) ) {
		$defines_well_formed = false;
		continue;
	}
	$defines_by_name[ $define['name'] ] = $define['value'];
}
kntnt_extractor_assert( $defines_well_formed, 'every define entry carries a name and a value key' );
kntnt_extractor_assert( array_key_exists( 'DB_PASSWORD', $defines_by_name ) && $defines_by_name['DB_PASSWORD'] === null, 'DB_PASSWORD appears with value null (redacted)' );
kntnt_extractor_assert( array_key_exists( 'AUTH_SALT', $defines_by_name ) && $defines_by_name['AUTH_SALT'] === null, 'AUTH_SALT (suffix *_SALT) appears with value null (redacted)' );
kntnt_extractor_assert( array_key_exists( 'NONCE_KEY', $defines_by_name ) && $defines_by_name['NONCE_KEY'] === null, 'NONCE_KEY (prefix NONCE_*) appears with value null (redacted)' );

// AC3 — a non-secret define is resolved to its LIVE runtime value, never the raw
// source expression: WP_MEMORY_LIMIT is defined by WordPress at boot, so its
// value is the runtime constant, not the fixture's '999M' source literal.
kntnt_extractor_assert( array_key_exists( 'WP_MEMORY_LIMIT', $defines_by_name ) && $defines_by_name['WP_MEMORY_LIMIT'] === constant( 'WP_MEMORY_LIMIT' ), 'WP_MEMORY_LIMIT resolves to its live runtime value, not the source expression' );

// AC3 — no seeded secret value leaks anywhere in the serialised response.
$body = (string) wp_json_encode( $data );
$no_leak = ! str_contains( $body, 'topsecret-password' ) && ! str_contains( $body, 'topsecret-auth-salt' ) && ! str_contains( $body, 'topsecret-nonce-key' );
kntnt_extractor_assert( $no_leak, 'no secret define value appears anywhere in the response body' );

// Leave the suite state clean for later files.
if ( $seeded_dropin ) {
	unlink( $object_cache );
}
remove_filter( 'upload_dir', $relocate_uploads );
remove_filter( 'kntnt_extractor_environment_wp_config_path', $point_config );
unlink( $fixture );
wp_set_current_user( 0 );
