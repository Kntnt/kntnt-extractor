<?php
/**
 * REST controller for the authorized read-only environment-facts endpoint.
 *
 * @package Kntnt\Extractor
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Rest;

use Kntnt\Extractor\Authorizer;
use Kntnt\Extractor\Config;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers and answers `GET /kntnt-extractor/v1/environment`.
 *
 * Returns the site's generic runtime and configuration facts — PHP version, the
 * database engine's flavour/version/collation, WordPress URLs/paths/prefix/core
 * version, the active-plugins option, the drop-ins present, and the install's
 * resolved non-secret `wp-config` defines. These are the facts a migration or
 * staging caller needs that no other surface can supply: the live PHP and DB
 * versions appear in no file and no table dump, and the defines can otherwise be
 * recovered only by shipping the whole `wp-config.php` — secrets and all — off
 * the server. Resolving the defines here, with the secret family redacted to
 * `null`, keeps the database password and salts on the server (the reason this
 * endpoint exists) while staying strictly generic: it exposes only facts about
 * the install, never any caller-specific categorisation (ADR-0003). Access is
 * the plugin's single two-capability gate, applied through the shared
 * {@see Authorizer} (ADR-0002); the endpoint carries no caller-supplied resource,
 * is read-only, and has no side effects.
 *
 * @since 0.2.0
 */
final class Environment_Controller {

	/**
	 * The exact define names whose value is a secret and is never read.
	 *
	 * The suffix `*_SALT` and the prefix `NONCE_*` families are matched
	 * separately in {@see self::is_secret_define()}; this list is the fixed-name
	 * remainder. Mirrors `kntnt-wp-skills`'s `is_secret_define()`, giving defence
	 * in depth at both ends of the boundary.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const array SECRET_DEFINE_NAMES = [ 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY' ];

	/**
	 * Wires the controller to the shared authorization gate and the Config seam.
	 *
	 * The Config seam resolves the `wp_config_path` knob, so a site (or a test)
	 * can point define discovery at a specific `wp-config.php`; the default locates
	 * it the way WordPress core does.
	 *
	 * @since 0.2.0
	 *
	 * @param Authorizer $authorizer The shared both-capabilities access gate.
	 * @param Config     $config     The constant-then-filter configuration seam.
	 */
	public function __construct(
		private readonly Authorizer $authorizer,
		private readonly Config $config,
	) {}

	/**
	 * Registers the environment route. Hooked on `rest_api_init`.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			Status_Controller::REST_NAMESPACE,
			'/environment',
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => $this->get_environment( ... ),
				'permission_callback' => $this->authorizer->authorize( ... ),
			],
		);

	}

	/**
	 * Returns the environment facts to an authorized caller.
	 *
	 * Every path is reported relative to the installation root, so no absolute
	 * server path is disclosed (least disclosure); every define in the secret
	 * family is reported by name with a `null` value, its value never read.
	 *
	 * @since 0.2.0
	 *
	 * @return WP_REST_Response The facts, shaped as the endpoint's contract.
	 */
	public function get_environment(): WP_REST_Response {

		// Best-effort web-server banner from the request environment; informational.
		// The superglobal is an untrusted, mixed-typed boundary: narrow it to a string
		// before unslashing and sanitising, and fall back to empty when it is absent.
		$server_software = '';
		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$server_software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
		}

		return new WP_REST_Response(
			[
				'php_version' => PHP_VERSION,
				'server_software' => $server_software,
				'wordpress' => $this->wordpress_facts(),
				'database' => $this->database_facts(),
				'active_plugins' => $this->active_plugins(),
				'dropins' => $this->dropins(),
				'defines' => $this->defines(),
			]
		);

	}

	/**
	 * Collects the generic WordPress facts, paths relative to the install root.
	 *
	 * @since 0.2.0
	 *
	 * @return array{core_version:string, home_url:string, site_url:string, table_prefix:string, content_dir:string, uploads_dir:string}
	 */
	private function wordpress_facts(): array {

		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		return [
			'core_version' => get_bloginfo( 'version' ),
			'home_url' => home_url(),
			'site_url' => site_url(),
			'table_prefix' => $wpdb->prefix,
			'content_dir' => $this->relative_to_root( WP_CONTENT_DIR ),
			'uploads_dir' => $this->relative_to_root( wp_upload_dir()['basedir'] ),
		];

	}

	/**
	 * Expresses an absolute path relative to the installation root.
	 *
	 * Least disclosure: the contract reports layout, never an absolute server
	 * path. A path under the root loses the root prefix; a path outside it (a
	 * non-default `WP_CONTENT_DIR` moved elsewhere) is expressed with leading
	 * `../` segments — still relative, still disclosing no absolute prefix. Both
	 * sides are normalised to forward slashes with no trailing slash first, so the
	 * prefix comparison is stable across platforms.
	 *
	 * @since 0.2.0
	 *
	 * @param string $absolute_path An absolute filesystem path to relativise.
	 * @return string The path relative to the installation root.
	 */
	private function relative_to_root( string $absolute_path ): string {

		// Normalise both paths to a comparable, slash-consistent, untrailed form.
		$root = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$target = untrailingslashit( wp_normalize_path( $absolute_path ) );

		// A path at or under the root is the root prefix stripped away.
		if ( $target === $root ) {
			return '';
		}
		if ( str_starts_with( $target, $root . '/' ) ) {
			return substr( $target, strlen( $root ) + 1 );
		}

		// A path outside the root is expressed by walking up past the divergent
		// tail of the root, then down into the target's own tail.
		$root_parts = explode( '/', trim( $root, '/' ) );
		$target_parts = explode( '/', trim( $target, '/' ) );
		$common = 0;
		while ( isset( $root_parts[ $common ], $target_parts[ $common ] ) && $root_parts[ $common ] === $target_parts[ $common ] ) {
			++$common;
		}
		$up = array_fill( 0, count( $root_parts ) - $common, '..' );
		$down = array_slice( $target_parts, $common );

		return implode( '/', [ ...$up, ...$down ] );

	}

	/**
	 * Derives the database engine's flavour, version, and default collation.
	 *
	 * The flavour is read from `@@version_comment`/`VERSION()`: a string mentioning
	 * MariaDB resolves to `mariadb`, everything else to `mysql`. These come from a
	 * live query because a table dump carries no `-- Server version` header, so the
	 * facts exist nowhere else the caller can reach.
	 *
	 * @since 0.2.0
	 *
	 * @return array{server:string, version:string, collation:string}
	 */
	private function database_facts(): array {

		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Read the version banner, the version, and the default collation directly;
		// no caller input reaches these constant queries. The banner disambiguates
		// MariaDB, which brands itself in @@version_comment.
		$version_comment = (string) $wpdb->get_var( 'SELECT @@version_comment' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$version = (string) $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$collation = (string) $wpdb->get_var( 'SELECT @@collation_database' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return [
			'server' => self::database_flavour( $version_comment, $version ),
			'version' => $version,
			'collation' => $collation,
		];

	}

	/**
	 * Classifies the database engine's flavour from its version banners.
	 *
	 * A pure classifier over the two strings a live engine reports for itself —
	 * `@@version_comment` and `VERSION()`. Any banner mentioning MariaDB — which
	 * brands itself in both — resolves to `mariadb`; every other engine, MySQL
	 * included, resolves to `mysql`. Kept pure and static so the classification
	 * rule can be pinned directly against fixed MySQL and MariaDB banners, without
	 * a live engine of each flavour to hand.
	 *
	 * @since 0.2.0
	 *
	 * @param string $version_comment The engine's `@@version_comment` banner.
	 * @param string $version         The engine's `VERSION()` string.
	 * @return string Either `mariadb` or `mysql`.
	 */
	public static function database_flavour( string $version_comment, string $version ): string {
		return stripos( $version_comment . ' ' . $version, 'mariadb' ) !== false ? 'mariadb' : 'mysql';
	}

	/**
	 * Returns the active-plugins option verbatim, as a list of plugin paths.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The `active_plugins` option's plugin-file paths.
	 */
	private function active_plugins(): array {

		// Normalise the option to a list of strings; a malformed option collapses
		// to an empty list rather than leaking non-string junk into the contract.
		$active = get_option( 'active_plugins', [] );
		return array_values( array_filter( is_array( $active ) ? $active : [], 'is_string' ) );

	}

	/**
	 * Returns the filenames of the drop-ins currently present under wp-content.
	 *
	 * `get_dropins()` scans `WP_CONTENT_DIR` and returns only the drop-ins that
	 * both exist and are recognised, keyed by filename — exactly the "present
	 * drop-ins" the contract wants. It lives in an admin-only include a REST
	 * request need not have loaded, so it is required on demand.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The present drop-in filenames (e.g. `object-cache.php`).
	 */
	private function dropins(): array {

		// Load the admin plugin API on demand, then list only the present drop-ins.
		if ( ! function_exists( 'get_dropins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array_values( array_filter( array_keys( get_dropins() ), 'is_string' ) );

	}

	/**
	 * Resolves each `wp-config` define to a `{ name, value }` record.
	 *
	 * Names come from a light regex over the located `wp-config.php` source — the
	 * same "find `define('NAME'`" approach the caller used to run itself — and each
	 * value is resolved live via `constant()`, never from the raw unevaluated
	 * source expression. A name in the secret family is emitted with `value: null`
	 * and its value is never read, so the database password, keys, salts, and
	 * nonces never cross the boundary even for this authorized caller.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{name:string, value:string|int|float|bool|null}>
	 */
	private function defines(): array {

		// Read the located wp-config source; an unreadable or absent file yields no
		// defines rather than an error — the rest of the facts still stand.
		$path = $this->wp_config_path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local trusted file, not a remote URL; wp_remote_get() does not apply.
		$source = ( $path !== '' && is_readable( $path ) ) ? (string) file_get_contents( $path ) : '';

		// Extract each defined name in source order, without duplicates. Only the
		// name is taken from the source; the value is resolved live below.
		preg_match_all( '/\bdefine\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $source, $matches );
		$names = array_values( array_unique( $matches[1] ) );

		// Resolve each name to its live value, redacting the secret family to null
		// and never reading its constant. A non-scalar value collapses to null,
		// keeping the contract to scalar-or-null.
		$defines = [];
		foreach ( $names as $name ) {
			$value = null;
			if ( ! $this->is_secret_define( $name ) && defined( $name ) ) {
				$resolved = constant( $name );
				$value = is_scalar( $resolved ) ? $this->relativise_define_value( $resolved ) : null;
			}
			$defines[] = [
				'name' => $name,
				'value' => $value,
			];
		}

		return $defines;

	}

	/**
	 * Relativises any absolute filesystem path carried by a define's value.
	 *
	 * Least disclosure applies to define values just as it does to the reported
	 * paths: the stock `wp-config.php` always defines `ABSPATH` to the absolute
	 * install root, and a site may set path-valued defines (`WP_CONTENT_DIR`,
	 * `WP_TEMP_DIR`, a path-form `WP_DEBUG_LOG`, a socket-path `DB_HOST`). Emitting
	 * those verbatim would hand out the very absolute prefix {@see self::relative_to_root()}
	 * strips from `content_dir`/`uploads_dir`. Any string value that normalises to
	 * an absolute path (a leading `/` or a drive letter) is therefore expressed
	 * relative to the install root; every other scalar passes through unchanged.
	 *
	 * @since 0.2.0
	 *
	 * @param string|int|float|bool $value The resolved scalar define value.
	 * @return string|int|float|bool The value, with any absolute path relativised.
	 */
	private function relativise_define_value( string|int|float|bool $value ): string|int|float|bool {

		// Only string values can be paths; leave every other scalar untouched.
		if ( ! is_string( $value ) ) {
			return $value;
		}

		// An absolute path (POSIX root or a drive letter) is relativised to the
		// install root; anything else is not a path and is emitted as-is.
		$normalized = wp_normalize_path( $value );
		if ( str_starts_with( $normalized, '/' ) || preg_match( '#^[A-Za-z]:/#', $normalized ) === 1 ) {
			return $this->relative_to_root( $value );
		}

		return $value;

	}

	/**
	 * Decides whether a define name belongs to the redaction family.
	 *
	 * The family is fixed and caller-independent: the four exact key names, any
	 * name ending `_SALT`, and any name beginning `NONCE_`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The define name to classify.
	 * @return bool True when the name's value is a secret and must be redacted.
	 */
	private function is_secret_define( string $name ): bool {
		return in_array( $name, self::SECRET_DEFINE_NAMES, true )
			|| str_ends_with( $name, '_SALT' )
			|| str_starts_with( $name, 'NONCE_' );
	}

	/**
	 * Locates the `wp-config.php` whose defines are read.
	 *
	 * Honours the `wp_config_path` Config knob first, so a site or test can name
	 * the file explicitly; otherwise it looks where WordPress core does — in the
	 * install root, then one directory up (the split-config layout core supports
	 * when no `wp-settings.php` sits beside the parent's `wp-config.php`).
	 *
	 * @since 0.2.0
	 *
	 * @return string The resolved path, or an empty string when none is found.
	 */
	private function wp_config_path(): string {

		// A configured path wins outright.
		$configured = $this->config->get( 'wp_config_path', '' );
		if ( is_string( $configured ) && $configured !== '' ) {
			return $configured;
		}

		// Otherwise mirror core's search: the install root, then one level up when
		// that parent file is not another install's wp-settings neighbour.
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$parent = dirname( ABSPATH ) . '/wp-config.php';
		if ( file_exists( $parent ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return $parent;
		}

		return '';

	}

}
