<?php
/**
 * Configuration seam.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * Resolves a configuration value from a constant, overridable by a filter.
 *
 * This is the single seam every later component reads its settings through, so
 * a site can hard-wire a value with a `wp-config.php` constant yet still let a
 * plugin or must-use file override it at runtime. It intentionally exposes no
 * knobs of its own yet — the walking skeleton only needs the seam to exist and
 * to honour the resolution order below.
 *
 * Resolution order for a knob named `foo`:
 *
 * 1. The constant `KNTNT_EXTRACTOR_FOO`, when defined, supplies the base value;
 *    otherwise the caller's default does.
 * 2. The filter `kntnt_extractor_config_foo` then runs on that value, and its
 *    return value is authoritative — a filter always wins over the constant.
 *
 * @since 0.1.0
 */
final class Config {

	/**
	 * Prefix for the constant a knob resolves from.
	 *
	 * The knob name is upper-cased and appended, so `foo` reads
	 * `KNTNT_EXTRACTOR_FOO`.
	 *
	 * @since 0.1.0
	 */
	private const string CONSTANT_PREFIX = 'KNTNT_EXTRACTOR_';

	/**
	 * Prefix for the filter a knob passes through.
	 *
	 * The knob name is appended verbatim, so `foo` runs the
	 * `kntnt_extractor_config_foo` filter.
	 *
	 * @since 0.1.0
	 */
	private const string FILTER_PREFIX = 'kntnt_extractor_config_';

	/**
	 * Resolves a configuration value.
	 *
	 * The constant supplies the base value when defined, otherwise `$fallback`.
	 * The value then passes through the knob's filter, whose return value wins —
	 * this is what lets a runtime override beat a hard-wired constant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name     Knob name in lower_snake_case.
	 * @param mixed  $fallback Value to use when the constant is undefined.
	 * @return mixed The resolved value, after the filter has had its say.
	 */
	public function get( string $name, mixed $fallback = null ): mixed {

		// Start from the constant when defined, else the caller's fallback.
		$constant = self::CONSTANT_PREFIX . strtoupper( $name );
		$value = defined( $constant ) ? constant( $constant ) : $fallback;

		// Let a filter have the final word; its return value is authoritative.
		return apply_filters( self::FILTER_PREFIX . $name, $value, $name );

	}

}
