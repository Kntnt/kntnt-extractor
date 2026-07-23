<?php
/**
 * The fixed deny-list of credential-bearing path patterns.
 *
 * @package Kntnt\Extractor
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * Matches an install-root-relative file path against the credential-bearing
 * deny-list (ADR-0011).
 *
 * A restricted path is never a valid extraction target: `Extractions_Controller`
 * rejects a `POST /extractions` selection containing one at validation time,
 * before the existence check, naming every offending path. `GET /files` is
 * unaffected — the manifest keeps listing a restricted path unannotated, as
 * ADR-0003 requires.
 *
 * Matching runs case-insensitively against a path normalised to `/`-separators,
 * so it holds however a caller spells a Windows-style path. The three pattern
 * classes below are the single shared constant the ADR calls for: `wp-config.php`
 * and its backup/editor-droppings siblings and `.env`/`.env.*` match by basename
 * anywhere in the tree — the safer, superset reading, since a real installation
 * never legitimately carries either name outside the root — while database dumps
 * and key material match only directly in the installation root, per the ADR.
 *
 * @since 0.3.0
 */
final class Restricted_Path {

	/**
	 * Basename patterns for `wp-config.php` and its credential-bearing siblings,
	 * matched anywhere in the tree. `wp-config-sample.php` holds no secrets and is
	 * explicitly excepted by the third pattern's negative lookahead.
	 *
	 * @since 0.3.0
	 */
	private const array WP_CONFIG_PATTERNS = [
		'/^wp-config\.php$/i',
		'/^wp-config\.php\..+$/i',
		'/^wp-config\.php~$/i',
		'/^wp-config-(?!sample\.php$).+\.php$/i',
	];

	/**
	 * Basename patterns for `.env` and its siblings, matched anywhere in the tree.
	 *
	 * @since 0.3.0
	 */
	private const array ENV_PATTERNS = [
		'/^\.env$/i',
		'/^\.env\..+$/i',
	];

	/**
	 * Basename patterns for database dumps and key material, matched only when
	 * the path is directly in the installation root.
	 *
	 * @since 0.3.0
	 */
	private const array ROOT_ONLY_PATTERNS = [
		'/\.sql$/i',
		'/\.sql\.gz$/i',
		'/\.sql\.zip$/i',
		'/\.pem$/i',
		'/\.key$/i',
		'/^id_rsa/i',
	];

	/**
	 * Returns every requested path that matches the deny-list, in selection order.
	 *
	 * @since 0.3.0
	 *
	 * @param array<int, string> $paths The requested installation-root-relative paths.
	 * @return array<int, string> Every restricted path found, empty when none match.
	 */
	public static function matches( array $paths ): array {

		return array_values( array_filter( $paths, self::is_restricted( ... ) ) );

	}

	/**
	 * Reports whether a single path matches the deny-list.
	 *
	 * @since 0.3.0
	 *
	 * @param string $path An installation-root-relative path, as the caller sent it.
	 * @return bool True when the path is restricted.
	 */
	public static function is_restricted( string $path ): bool {

		// Normalise Windows-style separators so a backslash-spelled path is judged
		// the same as a forward-slash one, then isolate the final path component:
		// every pattern except the root-only family matches on the basename alone.
		$normalized = str_replace( '\\', '/', $path );
		$basename = basename( $normalized );

		foreach ( [ ...self::WP_CONFIG_PATTERNS, ...self::ENV_PATTERNS ] as $pattern ) {
			if ( preg_match( $pattern, $basename ) === 1 ) {
				return true;
			}
		}

		if ( self::is_root_level( $normalized ) ) {
			foreach ( self::ROOT_ONLY_PATTERNS as $pattern ) {
				if ( preg_match( $pattern, $basename ) === 1 ) {
					return true;
				}
			}
		}

		return false;

	}

	/**
	 * Reports whether a normalised path names a file directly in the installation
	 * root, with no intervening directory.
	 *
	 * @since 0.3.0
	 *
	 * @param string $normalized A `/`-separated path, possibly leading with `./`.
	 * @return bool True when the path carries no directory component.
	 */
	private static function is_root_level( string $normalized ): bool {

		$trimmed = $normalized;
		while ( str_starts_with( $trimmed, './' ) ) {
			$trimmed = substr( $trimmed, 2 );
		}

		return ! str_contains( ltrim( $trimmed, '/' ), '/' );

	}

}
