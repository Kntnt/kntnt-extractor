<?php
/**
 * Integration test: the self-hosted update checker (issue #12).
 *
 * Proves the four acceptance criteria of the update path, without any network
 * round trip. The YahnisElsts Plugin Update Checker is bundled and loads (AC1);
 * the plugin's own `Update_Checker` points it at this repository's GitHub
 * releases and constrains it to the release asset selected BY NAME, never
 * positionally, refusing to fall back to the auto-generated source archive
 * (AC2); the bootstrap has already hooked the plugin-update transient so an
 * available update surfaces on the Plugins screen (AC2/AC4); and the name the
 * checker matches is exactly the filename `build-release-zip.sh` emits, so the
 * in-place update needs no manual file replacement (AC4, closed with the build
 * test).
 *
 * The asset-name match is the failure surface ADR-0005 flags: if the configured
 * name and the built asset name ever drift, self-update silently breaks. This
 * suite pins the match to a required, by-name selection; `build-release-zip.sh`
 * is pinned to the same literal from the other side by the build test.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Plugin;
use Kntnt\Extractor\Update_Checker;

// Reads a protected property off a PUC object so the test can inspect the wiring
// the library exposes no getter for. Scoped to this file.
$read_prop = static function ( object $object, string $property ): mixed {
	$reflection = new ReflectionProperty( $object, $property );
	$reflection->setAccessible( true );

	return $reflection->getValue( $object );
};

// AC1: the bundled YahnisElsts Plugin Update Checker library is present and
// loaded — its version-stable factory alias resolves.
$puc_factory = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
kntnt_extractor_assert( class_exists( $puc_factory ), 'AC1: the YahnisElsts Plugin Update Checker library is bundled and loaded' );

// The plugin's own wiring class exists.
$has_wrapper = class_exists( Update_Checker::class );
kntnt_extractor_assert( $has_wrapper, 'AC1: the Update_Checker wiring class exists' );

// AC1: the checker is pointed at this plugin's own GitHub repository.
$repository_url = ( $has_wrapper && defined( Update_Checker::class . '::REPOSITORY_URL' ) )
	? (string) constant( Update_Checker::class . '::REPOSITORY_URL' )
	: '';
kntnt_extractor_assert( str_contains( $repository_url, 'github.com/Kntnt/kntnt-extractor' ), 'AC1: Update_Checker targets the plugin\'s own GitHub repository' );

// AC2/AC3: the matched asset name is exactly the filename the build script
// emits — the single literal both sides must agree on for self-update to work.
$asset_name = ( $has_wrapper && defined( Update_Checker::class . '::ASSET_NAME' ) )
	? (string) constant( Update_Checker::class . '::ASSET_NAME' )
	: '';
kntnt_extractor_assert( $asset_name === 'kntnt-extractor.zip', 'AC2: the release asset name is kntnt-extractor.zip' );

// AC2: the name pattern selects the real asset by name but rejects a foreign
// one — selection is by name, not by position in the asset list.
$pattern = ( $has_wrapper && method_exists( Update_Checker::class, 'asset_name_pattern' ) )
	? Update_Checker::asset_name_pattern()
	: '';
$matches_own = $pattern !== '' && preg_match( $pattern, 'kntnt-extractor.zip' ) === 1;
$rejects_foreign = $pattern !== '' && preg_match( $pattern, 'some-other-plugin.zip' ) === 0;
kntnt_extractor_assert( $matches_own, 'AC2: the asset pattern matches the plugin\'s own release asset by name' );
kntnt_extractor_assert( $rejects_foreign, 'AC2: the asset pattern rejects a foreign asset name (by name, not positional)' );

// Retrieve the live checker the bootstrap already built and configured.
// register() is build-once, so this returns that same instance rather than
// constructing a colliding second checker for the same slug.
$checker = ( $has_wrapper && class_exists( $puc_factory ) && class_exists( Plugin::class ) )
	? ( new Update_Checker( Plugin::get_plugin_file() ) )->register()
	: null;
$api = $checker !== null ? $checker->getVcsApi() : null;

// AC2: release assets are enabled, so the update package is the named asset
// rather than GitHub's auto-generated source ZIP.
$assets_enabled = $api !== null && $read_prop( $api, 'releaseAssetsEnabled' ) === true;
kntnt_extractor_assert( $assets_enabled, 'AC2: the checker downloads the named release asset, not the source archive' );

// AC2: the library filters assets by exactly the plugin's own name pattern.
$filter_regex = $api !== null ? $read_prop( $api, 'assetFilterRegex' ) : null;
kntnt_extractor_assert( $pattern !== '' && $filter_regex === $pattern, 'AC2: the library filters release assets by the plugin\'s name pattern' );

// AC2: a release without a name-matching asset is rejected, never resolved
// positionally to whatever asset or source archive happens to be first.
$require_preference = $api !== null && $read_prop( $api, 'releaseAssetPreference' ) === $api::REQUIRE_RELEASE_ASSETS;
kntnt_extractor_assert( $require_preference, 'AC2: an absent named asset is refused, never matched positionally' );

// AC1: the built checker resolves to this plugin's GitHub repository.
$metadata_url = $checker !== null ? (string) $read_prop( $checker, 'metadataUrl' ) : '';
kntnt_extractor_assert( str_contains( $metadata_url, 'github.com/Kntnt/kntnt-extractor' ), 'AC1: the built update checker resolves to the plugin\'s GitHub repository' );

// AC2/AC4: the bootstrap already registered the update checker, so PUC hooks the
// plugin-update transient and an available update shows on the Plugins screen —
// the update installs in place, with no manual file replacement.
kntnt_extractor_assert( has_filter( 'site_transient_update_plugins' ) !== false, 'AC2/AC4: an available update is advertised through the plugin-update transient' );
