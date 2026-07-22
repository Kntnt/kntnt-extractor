<?php
/**
 * Integration test: GET /environment returns read-only site & runtime facts,
 * gated by the shared Authorizer, with the secret define family redacted.
 *
 * This harness exercises the endpoint end to end against the live REST stack:
 * the both-capabilities gate (AC2 — 403 for anonymous and single-capability
 * callers), the response shape (AC1 — php/database/wordpress/active_plugins/
 * dropins/defines), the secret-define redaction (AC3 — a seeded DB_PASSWORD and
 * salt/nonce family emitted by name with value null, never their value), and the
 * relative content/uploads paths (AC4 — no absolute server path). The real
 * php_version and database.{server,version} magnitudes cannot be asserted here —
 * Playground runs on SQLite and cannot report a MySQL server version — so those
 * live in the DDEV harness (tests/Integration/DDEV/environment-test.php),
 * exactly as tables-size-test.php notes for SHOW TABLE STATUS.
 *
 * @package Kntnt\Extractor
 * @since   0.2.0
 */

declare( strict_types = 1 );

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';
$route = '/kntnt-extractor/v1/environment';

/**
 * Dispatches GET /environment through the live REST server.
 *
 * @return WP_REST_Response
 */
$get_environment = static fn(): WP_REST_Response => rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// --- AC2: the both-capabilities Authorizer gates the endpoint ----------------

// Neither an anonymous caller nor an Operate-only caller may read the facts.
wp_set_current_user( 0 );
kntnt_extractor_assert( $get_environment()->get_status() === 403, 'AC2: an anonymous caller is refused GET /environment (403)' );
$operate_only = wp_insert_user( [ 'user_login' => 'kntnt_env_operate_only', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
( new WP_User( $operate_only ) )->add_cap( $operate );
wp_set_current_user( $operate_only );
kntnt_extractor_assert( $get_environment()->get_status() === 403, 'AC2: Operate without manage_options is refused GET /environment (403)' );
$manage_only = wp_insert_user( [ 'user_login' => 'kntnt_env_manage_only', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
( new WP_User( $manage_only ) )->add_cap( 'manage_options' );
wp_set_current_user( $manage_only );
kntnt_extractor_assert( $get_environment()->get_status() === 403, 'AC2: manage_options without Operate is refused GET /environment (403)' );

// --- Arrange a seeded define fixture and a non-default uploads layout ---------

// Point the controller at a fixture wp-config.php whose source names the secret
// families (so redaction can be proven) plus a resolvable non-secret define. The
// controller only reads NAMES from this source and resolves values live via
// constant(); it never evaluates the fixture, so the seeded secret literals below
// exist purely to prove they are NOT echoed back.
$fixture = wp_upload_dir()['basedir'] . '/kntnt-env-wp-config-fixture.php';
wp_mkdir_p( dirname( $fixture ) );
file_put_contents( $fixture, <<<'PHP'
<?php
define( 'DB_PASSWORD', 'super-secret-db-password' );
define( 'AUTH_KEY', 'seeded-auth-key' );
define( 'SECURE_AUTH_KEY', 'seeded-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'seeded-logged-in-key' );
define( 'AUTH_SALT', 'seeded-auth-salt' );
define( 'NONCE_SALT', 'seeded-nonce-salt' );
define( 'NONCE_KEY', 'seeded-nonce-key' );
define( 'KNTNT_TEST_CUSTOM_SALT', 'seeded-custom-salt-literal' );
define( 'NONCE_KNTNT_TEST', 'seeded-nonce-prefix-literal' );
define( 'KNTNT_ENV_TEST_DEFINE', 'resolved-value' );
define( 'ABSPATH', '/must/never/be/read-from-source' );
define( 'KNTNT_ENV_TEST_ABS_PATH', '/must/never/be/read-from-source' );
PHP );
$point_config = static fn(): string => $fixture;
add_filter( 'kntnt_extractor_config_wp_config_path', $point_config );

// Define the non-secret constant at runtime so its live value is resolvable.
if ( ! defined( 'KNTNT_ENV_TEST_DEFINE' ) ) {
	define( 'KNTNT_ENV_TEST_DEFINE', 'resolved-value' );
}

// Define the non-canonical secret-family members live with distinctive literals,
// so the response proving their value is null also proves the controller never
// reads the live constant of a suffix/prefix-matched name (AC3).
if ( ! defined( 'KNTNT_TEST_CUSTOM_SALT' ) ) {
	define( 'KNTNT_TEST_CUSTOM_SALT', 'live-custom-salt-value' );
}
if ( ! defined( 'NONCE_KNTNT_TEST' ) ) {
	define( 'NONCE_KNTNT_TEST', 'live-nonce-prefix-value' );
}

// Define a path-valued non-secret constant at runtime, under the install root, so
// the response can prove absolute paths are relativised (AC4). ABSPATH is already
// a real constant resolving to the absolute install root; the controller must
// relativise it too rather than echo the server path.
if ( ! defined( 'KNTNT_ENV_TEST_ABS_PATH' ) ) {
	define( 'KNTNT_ENV_TEST_ABS_PATH', ABSPATH . 'wp-content/uploads' );
}

// Move the uploads base to a non-default location so the relative uploads_dir is
// exercised against a real override rather than only the default layout.
$custom_uploads = untrailingslashit( WP_CONTENT_DIR ) . '/kntnt-custom-uploads';
$move_uploads = static function ( array $dirs ) use ( $custom_uploads ): array {
	$dirs['basedir'] = $custom_uploads;
	$dirs['baseurl'] = 'http://example.test/kntnt-custom-uploads';
	$dirs['path'] = $custom_uploads;
	$dirs['url'] = $dirs['baseurl'];
	return $dirs;
};
add_filter( 'upload_dir', $move_uploads );

// --- AC1 / AC3 / AC4: authorize and read the facts ---------------------------

$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $admin->ID );
$response = $get_environment();
kntnt_extractor_assert( $response->get_status() === 200, 'AC1: an administrator (both caps) reads GET /environment (200)' );
$data = $response->get_data();
$data = is_array( $data ) ? $data : [];

// AC1: the top-level shape carries every promised group.
$has_php = isset( $data['php_version'] ) && is_string( $data['php_version'] ) && $data['php_version'] !== '';
$has_server_software = array_key_exists( 'server_software', $data ) && is_string( $data['server_software'] );
kntnt_extractor_assert( $has_php, 'AC1: php_version is a non-empty string' );
kntnt_extractor_assert( $has_php && $data['php_version'] === PHP_VERSION, 'AC1: php_version equals the running PHP version' );
kntnt_extractor_assert( $has_server_software, 'AC1: server_software is present (best-effort string)' );

// AC1: the wordpress group.
$wp = is_array( $data['wordpress'] ?? null ) ? $data['wordpress'] : [];
$wp_shape = is_string( $wp['core_version'] ?? null ) && ( $wp['core_version'] ?? '' ) !== ''
	&& is_string( $wp['home_url'] ?? null )
	&& is_string( $wp['site_url'] ?? null )
	&& is_string( $wp['table_prefix'] ?? null ) && ( $wp['table_prefix'] ?? '' ) !== ''
	&& is_string( $wp['content_dir'] ?? null )
	&& is_string( $wp['uploads_dir'] ?? null );
kntnt_extractor_assert( $wp_shape, 'AC1: wordpress carries core_version, home_url, site_url, table_prefix, content_dir, uploads_dir' );
global $wpdb;
kntnt_extractor_assert( ( $wp['core_version'] ?? '' ) === get_bloginfo( 'version' ), 'AC1: wordpress.core_version matches the running core version' );
kntnt_extractor_assert( ( $wp['home_url'] ?? '' ) === home_url() && ( $wp['site_url'] ?? '' ) === site_url(), 'AC1: wordpress.home_url/site_url match the site URLs' );
kntnt_extractor_assert( ( $wp['table_prefix'] ?? '' ) === $wpdb->prefix, 'AC1: wordpress.table_prefix matches $wpdb->prefix' );

// AC1: the database group is present and string-shaped (magnitudes are DDEV-only).
$db = is_array( $data['database'] ?? null ) ? $data['database'] : [];
$db_shape = in_array( $db['server'] ?? null, [ 'mysql', 'mariadb' ], true )
	&& is_string( $db['version'] ?? null )
	&& is_string( $db['collation'] ?? null );
kntnt_extractor_assert( $db_shape, 'AC1: database carries server (mysql|mariadb), version, collation' );

// AC1: active_plugins is a list of strings and reflects the option as-is.
$active = $data['active_plugins'] ?? null;
$active_ok = is_array( $active ) && array_is_list( $active ) && array_all( $active, static fn( $p ): bool => is_string( $p ) );
kntnt_extractor_assert( $active_ok, 'AC1: active_plugins is a list of plugin path strings' );
kntnt_extractor_assert( $active === array_values( (array) get_option( 'active_plugins', [] ) ), 'AC1: active_plugins mirrors the active_plugins option' );

// AC1: dropins is a list of strings.
$dropins = $data['dropins'] ?? null;
$dropins_ok = is_array( $dropins ) && array_is_list( $dropins ) && array_all( $dropins, static fn( $d ): bool => is_string( $d ) );
kntnt_extractor_assert( $dropins_ok, 'AC1: dropins is a list of present drop-in filenames' );

// AC1: defines is a list of { name, value } records.
$defines = $data['defines'] ?? null;
$defines_ok = is_array( $defines ) && array_is_list( $defines ) && $defines !== [];
$by_name = [];
if ( is_array( $defines ) ) {
	foreach ( $defines as $define ) {
		if ( is_array( $define ) && is_string( $define['name'] ?? null ) && array_key_exists( 'value', $define ) ) {
			$by_name[ $define['name'] ] = $define['value'];
		} else {
			$defines_ok = false;
		}
	}
}
kntnt_extractor_assert( $defines_ok, 'AC1: defines is a non-empty list of { name, value } records' );

// AC3: every secret in the redaction family appears by name with value null, and
// its seeded literal never appears anywhere in the serialised body.
// The family is the four exact names PLUS the suffix *_SALT PLUS the prefix
// NONCE_* — a pattern rule, not a name list. KNTNT_TEST_CUSTOM_SALT (suffix) and
// NONCE_KNTNT_TEST (prefix) are non-canonical members that must be redacted too:
// a regression to a hardcoded list of the seven canonical names would let these
// through, so they stand guard over the pattern behaviour.
$secret_names = [ 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'AUTH_SALT', 'NONCE_SALT', 'NONCE_KEY', 'KNTNT_TEST_CUSTOM_SALT', 'NONCE_KNTNT_TEST' ];
$all_redacted = true;
foreach ( $secret_names as $name ) {
	if ( ! array_key_exists( $name, $by_name ) || $by_name[ $name ] !== null ) {
		$all_redacted = false;
	}
}
kntnt_extractor_assert( $all_redacted, 'AC3: every secret-family define — including a *_SALT-suffix and a NONCE_-prefix member — is present by name with value null' );
$body = (string) wp_json_encode( $data );
$literals = [ 'super-secret-db-password', 'seeded-auth-key', 'seeded-secure-auth-key', 'seeded-logged-in-key', 'seeded-auth-salt', 'seeded-nonce-salt', 'seeded-nonce-key', 'seeded-custom-salt-literal', 'seeded-nonce-prefix-literal', 'live-custom-salt-value', 'live-nonce-prefix-value' ];
$leaked = false;
foreach ( $literals as $literal ) {
	if ( str_contains( $body, $literal ) ) {
		$leaked = true;
	}
}
kntnt_extractor_assert( ! $leaked, 'AC3: no seeded secret literal appears anywhere in the response body' );

// A non-secret define resolves to its live value.
kntnt_extractor_assert( ( $by_name['KNTNT_ENV_TEST_DEFINE'] ?? null ) === 'resolved-value', 'AC3: a non-secret define resolves to its live constant() value' );

// AC4: no defines value discloses an absolute server path. ABSPATH ships in every
// stock wp-config.php and always resolves to the absolute install root; a path-
// valued define does likewise. Both must be relativised, never echoed verbatim.
$abspath_root = untrailingslashit( wp_normalize_path( ABSPATH ) );
$no_absolute_define = true;
foreach ( $by_name as $define_value ) {
	if ( is_string( $define_value ) && ( str_starts_with( $define_value, '/' ) || preg_match( '#^[A-Za-z]:#', $define_value ) === 1 || str_contains( $define_value, $abspath_root ) ) ) {
		$no_absolute_define = false;
	}
}
kntnt_extractor_assert( $no_absolute_define, 'AC4: no defines value starts with an absolute path or discloses the install root' );
kntnt_extractor_assert( array_key_exists( 'ABSPATH', $by_name ) && $by_name['ABSPATH'] === '', 'AC4: ABSPATH is relativised to the empty root-relative path, not the absolute root' );
kntnt_extractor_assert( ( $by_name['KNTNT_ENV_TEST_ABS_PATH'] ?? null ) === 'wp-content/uploads', 'AC4: a path-valued define under the root is relativised to the root-relative path' );

// AC5: the flavour classifier is the rule itself, pinned against fixed banners of
// both engines — the Playground/SQLite backend never exercises a real MySQL or
// MariaDB banner, so the rule is asserted directly rather than only via VERSION().
$flavour = \Kntnt\Extractor\Rest\Environment_Controller::database_flavour( ... );
kntnt_extractor_assert( $flavour( 'MySQL Community Server - GPL', '8.0.36' ) === 'mysql', 'AC5: a MySQL banner classifies as mysql' );
kntnt_extractor_assert( $flavour( 'mariadb.org binary distribution', '10.11.6-MariaDB-1:10.11.6+maria~ubu2204' ) === 'mariadb', 'AC5: a MariaDB @@version_comment classifies as mariadb' );
kntnt_extractor_assert( $flavour( '', '5.5.5-10.6.16-MariaDB' ) === 'mariadb', 'AC5: a MariaDB VERSION() alone (empty comment) classifies as mariadb' );
kntnt_extractor_assert( $flavour( '', '8.0.36' ) === 'mysql', 'AC5: a bare MySQL VERSION() with no comment classifies as mysql' );

// AC4: content_dir and uploads_dir are relative to the install root — no leading
// slash, no drive letter, no absolute server path — and correct.
$content_dir = $wp['content_dir'] ?? '';
$uploads_dir = $wp['uploads_dir'] ?? '';
$is_relative = static fn( string $p ): bool => $p !== '' && ! str_starts_with( $p, '/' ) && ! preg_match( '#^[A-Za-z]:#', $p );
kntnt_extractor_assert( $is_relative( $content_dir ) && $is_relative( $uploads_dir ), 'AC4: content_dir and uploads_dir are relative paths (no absolute server path)' );
$abspath = untrailingslashit( wp_normalize_path( ABSPATH ) );
kntnt_extractor_assert( ! str_contains( $content_dir, $abspath ) && ! str_contains( $uploads_dir, $abspath ), 'AC4: neither relative path discloses the absolute install root' );
kntnt_extractor_assert( $uploads_dir === 'wp-content/kntnt-custom-uploads', 'AC4: uploads_dir tracks a non-default uploads layout, relative to the root' );

// AC4: an uploads base moved OUTSIDE the install root exercises relative_to_root's
// walk-up branch — the common-prefix walk plus '..' segments — which the under-root
// layout above never reaches. WP_CONTENT_DIR cannot be redefined in-process, but
// the same code path is reachable through the uploads override.
remove_filter( 'upload_dir', $move_uploads );
$outside_uploads = untrailingslashit( wp_normalize_path( dirname( ABSPATH ) ) ) . '/kntnt-outside-uploads';
$move_outside = static function ( array $dirs ) use ( $outside_uploads ): array {
	$dirs['basedir'] = $outside_uploads;
	$dirs['baseurl'] = 'http://example.test/kntnt-outside-uploads';
	$dirs['path'] = $outside_uploads;
	$dirs['url'] = $dirs['baseurl'];
	return $dirs;
};
add_filter( 'upload_dir', $move_outside );
$outside_data = $get_environment()->get_data();
$outside_data = is_array( $outside_data ) ? $outside_data : [];
$outside_wp = is_array( $outside_data['wordpress'] ?? null ) ? $outside_data['wordpress'] : [];
$outside_dir = $outside_wp['uploads_dir'] ?? '';
kntnt_extractor_assert( $outside_dir === '../kntnt-outside-uploads', 'AC4: an uploads base outside the root is expressed with a leading ../ walk-up' );
kntnt_extractor_assert( is_string( $outside_dir ) && ! str_starts_with( $outside_dir, '/' ) && preg_match( '#^[A-Za-z]:#', $outside_dir ) === 0 && ! str_contains( $outside_dir, $abspath ), 'AC4: the walk-up path discloses no absolute prefix' );
remove_filter( 'upload_dir', $move_outside );

// --- Clean up so later suite files see a neutral state -----------------------

remove_filter( 'kntnt_extractor_config_wp_config_path', $point_config );
@unlink( $fixture );
wp_set_current_user( 0 );
