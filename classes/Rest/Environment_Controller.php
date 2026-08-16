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
 * `wp-config` defines. These are the facts a migration or staging caller needs
 * that no other surface can supply: the live PHP and DB versions appear in no
 * file and no table dump, and the defines can otherwise be recovered only by
 * shipping the whole `wp-config.php` — secrets and all — off the server. Every
 * define name found in the source is always reported; a value discloses only
 * when {@see self::is_disclosable_define()} allows it, which keeps the database
 * password, the core secret family, and any third-party credential the site
 * happens to define on the server (the reason this endpoint exists) while
 * staying strictly generic: it exposes only facts about the install, never any
 * caller-specific categorisation (ADR-0003). See `docs/define-disclosure.md` for
 * the normative protocol a caller reads this against. Access is the plugin's
 * single two-capability gate, applied through the shared {@see Authorizer}
 * (ADR-0002); the endpoint carries no caller-supplied resource, is read-only,
 * and has no side effects.
 *
 * @since 0.2.0
 */
final class Environment_Controller {

	/**
	 * The define names whose value is safe to disclose to an authorized caller.
	 *
	 * These are the layout and behaviour facts a migration or staging caller
	 * genuinely needs — install paths and URLs, the non-secret database
	 * connection facts (`DB_NAME`, `DB_USER`, `DB_HOST`, `DB_CHARSET`,
	 * `DB_COLLATE`; deliberately not `DB_PASSWORD`), multisite topology, and the
	 * behavioural toggles core reads from `wp-config.php`. Membership is policy,
	 * not contract: it may change without moving {@see \Kntnt\Extractor\Rest\Status_Controller::API_VERSION},
	 * per the protocol in `docs/define-disclosure.md`. Every name here is still
	 * subject to {@see self::REDACTION_SUBSTRINGS} — the allow-list is not itself
	 * the last word.
	 *
	 * @since 0.6.0
	 *
	 * @var list<string>
	 */
	private const array DISCLOSABLE_DEFINE_NAMES = [
		'ABSPATH',
		'ADMIN_COOKIE_PATH',
		'AUTOMATIC_UPDATER_DISABLED',
		'AUTOSAVE_INTERVAL',
		'BLOG_ID_CURRENT_SITE',
		'COMPRESS_CSS',
		'COMPRESS_SCRIPTS',
		'CONCATENATE_SCRIPTS',
		'COOKIE_DOMAIN',
		'COOKIEPATH',
		'DB_CHARSET',
		'DB_COLLATE',
		'DB_HOST',
		'DB_NAME',
		'DB_USER',
		'DISABLE_WP_CRON',
		'DISALLOW_FILE_EDIT',
		'DISALLOW_FILE_MODS',
		'DOMAIN_CURRENT_SITE',
		'EMPTY_TRASH_DAYS',
		'FORCE_SSL_ADMIN',
		'FORCE_SSL_LOGIN',
		'FS_METHOD',
		'IMAGE_EDIT_OVERWRITE',
		'MEDIA_TRASH',
		'MULTISITE',
		'NOBLOGREDIRECT',
		'PATH_CURRENT_SITE',
		'PLUGINS_COOKIE_PATH',
		'RELOCATE',
		'SAVEQUERIES',
		'SCRIPT_DEBUG',
		'SITE_ID_CURRENT_SITE',
		'SITECOOKIEPATH',
		'STYLESHEETPATH',
		'SUBDOMAIN_INSTALL',
		'TEMPLATEPATH',
		'UPLOADS',
		'WP_ACCESSIBLE_HOSTS',
		'WP_ALLOW_MULTISITE',
		'WP_AUTO_UPDATE_CORE',
		'WP_CACHE',
		'WP_CONTENT_DIR',
		'WP_CONTENT_URL',
		'WP_CRON_LOCK_TIMEOUT',
		'WP_DEBUG',
		'WP_DEBUG_DISPLAY',
		'WP_DEBUG_LOG',
		'WP_DEFAULT_THEME',
		'WP_ENVIRONMENT_TYPE',
		'WP_HOME',
		'WP_LANG_DIR',
		'WP_MAX_MEMORY_LIMIT',
		'WP_MEMORY_LIMIT',
		'WP_PLUGIN_DIR',
		'WP_PLUGIN_URL',
		'WP_POST_REVISIONS',
		'WP_PROXY_HOST',
		'WP_PROXY_PORT',
		'WP_SITEURL',
		'WP_TEMP_DIR',
		'WPLANG',
	];

	/**
	 * Substrings that force redaction regardless of the allow-list.
	 *
	 * The backstop for a future core or third-party define nobody has added to
	 * {@see self::DISCLOSABLE_DEFINE_NAMES}: `wp-config.php` is conventionally
	 * where a site puts SMTP passwords, JWT signing secrets, API keys, licence
	 * keys, and secondary database credentials, and a deny-list of secrets fails
	 * open on every name nobody anticipated. Matched case-insensitively against
	 * the whole name, so it also catches an allow-listed name that should never
	 * have been added.
	 *
	 * @since 0.6.0
	 *
	 * @var list<string>
	 */
	private const array REDACTION_SUBSTRINGS = [
		'API',
		'AUTH',
		'CREDENTIAL',
		'KEY',
		'LICEN',
		'NONCE',
		'PASS',
		'PRIVATE',
		'SALT',
		'SECRET',
		'TOKEN',
	];

	/**
	 * The closed set of reasons a define record's `disclosure` member reports.
	 *
	 * Backs the per-record discriminator so the three wire values are stated once
	 * in code, keyed by a symbolic name, rather than as string literals scattered
	 * through {@see self::defines()}. See `docs/define-disclosure.md` for the
	 * normative protocol.
	 *
	 * @since 0.6.0
	 *
	 * @var array{INCLUDED: string, NOT_ALLOW_LISTED: string, SECRET: string}
	 */
	private const array DISCLOSURE_REASONS = [
		'INCLUDED' => 'included',
		'NOT_ALLOW_LISTED' => 'not_allow_listed',
		'SECRET' => 'secret',
	];

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
	 * server path is disclosed (least disclosure); every define not allowed by
	 * {@see self::is_disclosable_define()} is reported by name with a `null`
	 * value and a `disclosure` reason, its value never read.
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
	 * Resolves each `wp-config` define to a `{ name, value, disclosure }` record.
	 *
	 * Names come from a light regex over the located `wp-config.php` source — the
	 * same "find `define('NAME'`" approach the caller used to run itself — and each
	 * value is resolved live via `constant()`, never from the raw unevaluated
	 * source expression, and only when {@see self::is_disclosable_define()} allows
	 * it. `disclosure` is always present and is one of {@see self::DISCLOSURE_REASONS}:
	 * it says why a value is or is not there, so a value withheld by policy is
	 * never confused with a value that is genuinely `null` (see
	 * `docs/define-disclosure.md`). Names are never withheld — only values are
	 * gated — so a caller always learns what `wp-config.php` defines, even when it
	 * learns nothing about a given define's value.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{name:string, value:string|int|float|bool|null, disclosure:string}>
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

		// Resolve the operator's extra disclosable names once, so the same union
		// with the fixed allow-list governs every name below.
		$extra_disclosable = $this->disclosable_defines();

		// Resolve each name to its live value only when disclosure policy allows
		// it, and always report why. A non-scalar value collapses to null, keeping
		// the contract to scalar-or-null; `disclosure` still reads `included`
		// there, because it reports the policy decision, not whether resolution
		// happened to find a live value.
		$defines = [];
		foreach ( $names as $name ) {
			$value = null;
			if ( $this->is_disclosable_define( $name, $extra_disclosable ) ) {
				$disclosure = self::DISCLOSURE_REASONS['INCLUDED'];
				if ( defined( $name ) ) {
					$resolved = constant( $name );
					$value = is_scalar( $resolved ) ? $this->relativise_define_value( $resolved ) : null;
				}
			} else {
				$disclosure = $this->matches_redaction_substring( $name )
					? self::DISCLOSURE_REASONS['SECRET']
					: self::DISCLOSURE_REASONS['NOT_ALLOW_LISTED'];
			}
			$defines[] = [
				'name' => $name,
				'value' => $value,
				'disclosure' => $disclosure,
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
	 * Decides whether a define name's value is safe to disclose.
	 *
	 * Inverted from the deny-list this replaces: a deny-list of secrets fails
	 * open on every name nobody thought to add to it, and `wp-config.php` is
	 * conventionally where a site's third-party credentials — SMTP passwords,
	 * signing secrets, API keys, licence keys — live alongside WordPress core's
	 * own. An allow-list fails closed instead: a name discloses only when it is
	 * one of the layout/behaviour facts {@see self::DISCLOSABLE_DEFINE_NAMES}
	 * names or one an operator has explicitly opted in via `$extra_disclosable`
	 * (see {@see self::disclosable_defines()}), **and** it matches none of
	 * {@see self::REDACTION_SUBSTRINGS} — the backstop that catches a future core
	 * or third-party define nobody has curated yet, applied after the allow-list
	 * check, never before it, so it can veto an allow-listed name but an
	 * allow-listed name can never bypass it.
	 *
	 * @since 0.6.0
	 *
	 * @param string             $name The define name to classify.
	 * @param array<int, string> $extra_disclosable The operator's extra allow-listed names, from {@see self::disclosable_defines()}.
	 * @return bool True when the name's value may be disclosed.
	 */
	private function is_disclosable_define( string $name, array $extra_disclosable ): bool {

		$allow_listed = in_array( $name, self::DISCLOSABLE_DEFINE_NAMES, true ) || in_array( $name, $extra_disclosable, true );

		return $allow_listed && ! $this->matches_redaction_substring( $name );

	}

	/**
	 * Decides whether a define name looks like it names a secret.
	 *
	 * Case-insensitive substring match against {@see self::REDACTION_SUBSTRINGS}.
	 * Deliberately loose — a false positive only withholds a value that a future
	 * allow-list update or the escape hatch can still surface; a false negative
	 * would leak a credential.
	 *
	 * @since 0.6.0
	 *
	 * @param string $name The define name to classify.
	 * @return bool True when the name matches a redaction substring.
	 */
	private function matches_redaction_substring( string $name ): bool {

		foreach ( self::REDACTION_SUBSTRINGS as $substring ) {
			if ( stripos( $name, $substring ) !== false ) {
				return true;
			}
		}

		return false;

	}

	/**
	 * Resolves the operator-configured extra disclosable define names.
	 *
	 * Read through the `disclosable_defines` Config knob — the constant
	 * `KNTNT_EXTRACTOR_DISCLOSABLE_DEFINES` or the
	 * `kntnt_extractor_config_disclosable_defines` filter — exactly as
	 * {@see self::wp_config_path()} resolves `wp_config_path`. Per-site and
	 * explicit: naming a define here is a deliberate operator decision, never a
	 * default. A value that is not itself a list — a string, a boolean, an
	 * associative array — is ignored rather than trusted, so there is no way to
	 * spell "everything": the knob can only ever widen disclosure by a finite,
	 * named set of extra defines, each still subject to
	 * {@see self::matches_redaction_substring()}.
	 *
	 * @since 0.6.0
	 *
	 * @return list<string> The extra define names this site has opted to disclose.
	 */
	private function disclosable_defines(): array {

		// A non-list configured value is untrusted input from a filter or a
		// misconfigured constant; fail closed to the empty list rather than let it
		// widen disclosure in a way this method cannot account for.
		$configured = $this->config->get( 'disclosable_defines', [] );
		if ( ! is_array( $configured ) || ! array_is_list( $configured ) ) {
			return [];
		}

		return array_values( array_filter( $configured, 'is_string' ) );

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
