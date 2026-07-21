<?php
/**
 * Integration test: the self-hosted update checker (issue #12).
 *
 * Proves the four acceptance criteria of the update path with no network round
 * trip. The YahnisElsts Plugin Update Checker is bundled and loads (AC1); the
 * plugin's own `Update_Checker` points it at this repository's GitHub releases
 * and constrains it to the release asset selected BY NAME, never positionally,
 * refusing to fall back to the auto-generated source archive (AC2); the
 * bootstrap has already hooked the plugin-update transient so an available
 * update surfaces on the Plugins screen (AC2/AC4); and the name the checker
 * matches is exactly the filename `build-release-zip.sh` emits, so the in-place
 * update needs no manual file replacement (AC4, closed with the build test).
 *
 * The asset-name match is the failure surface ADR-0005 flags: if the configured
 * name and the built asset name ever drift, self-update silently breaks. Rather
 * than reflect into the bundled library's protected wiring — which would couple
 * the test to one library version's internals — the suite feeds the live
 * checker a canned GitHub "latest release" response through `pre_http_request`
 * (so no real request leaves the process) and asserts the OBSERVABLE outcome:
 * the download URL the checker resolves. A release that lists a foreign asset
 * first and the plugin's own asset second must still resolve to the plugin's
 * asset (by name, not position, and not the source ZIP); a release carrying no
 * matching asset must resolve to no update at all.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Plugin;
use Kntnt\Extractor\Update_Checker;

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

// Intercept every GitHub API request so the checker resolves against a canned
// release instead of the network. The handler serves the current $fake_release
// for the "latest release" endpoint and refuses every other endpoint, so no tag
// or branch fallback can resolve a source archive behind our back. It also
// records the requested URL so the test can prove the checker calls its own
// repository.
$fake_release = null;
$requested_url = '';
$intercept = static function ( $preempt, array $args, string $url ) use ( &$fake_release, &$requested_url ) {

	if ( ! str_contains( $url, 'api.github.com' ) ) {
		return $preempt;
	}

	$requested_url = $url;
	if ( str_contains( $url, '/releases/latest' ) && $fake_release !== null ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => (string) wp_json_encode( $fake_release ) ];
	}

	return [ 'response' => [ 'code' => 404 ], 'body' => '' ];

};
add_filter( 'pre_http_request', $intercept, 10, 3 );

// AC2: a release whose asset list puts a foreign asset FIRST and the plugin's
// own asset SECOND must still resolve to the plugin's asset — proving selection
// is by name, not by position, and not the auto-generated source ZIP whose URL
// the reference otherwise starts out holding.
$own_asset_url = 'https://github.com/Kntnt/kntnt-extractor/releases/download/v99.0.0/kntnt-extractor.zip';
$fake_release = [
	'tag_name'    => 'v99.0.0',
	'created_at'  => '2099-01-01T00:00:00Z',
	'zipball_url' => 'https://api.github.com/repos/Kntnt/kntnt-extractor/zipball/v99.0.0',
	'assets'      => [
		[
			'name'                 => 'some-other-plugin.zip',
			'url'                  => 'https://api.github.com/repos/Kntnt/kntnt-extractor/releases/assets/1',
			'browser_download_url' => 'https://github.com/Kntnt/kntnt-extractor/releases/download/v99.0.0/some-other-plugin.zip',
			'download_count'       => 0,
		],
		[
			'name'                 => 'kntnt-extractor.zip',
			'url'                  => 'https://api.github.com/repos/Kntnt/kntnt-extractor/releases/assets/2',
			'browser_download_url' => $own_asset_url,
			'download_count'       => 3,
		],
	],
];
$resolved = $api !== null ? $api->chooseReference( 'master' ) : null;

// AC1: the checker resolved against this plugin's own GitHub repository — read
// off the URL it actually requested, not off any private wiring.
kntnt_extractor_assert( str_contains( $requested_url, 'Kntnt/kntnt-extractor' ), 'AC1: the built update checker requests this plugin\'s own GitHub repository' );

// AC2: the resolved download URL is the by-name asset, chosen over the foreign
// asset that came first and over the source ZIP.
$resolved_url = $resolved !== null ? (string) $resolved->downloadUrl : '';
kntnt_extractor_assert( $resolved_url === $own_asset_url, 'AC2: the checker resolves the update to the named release asset, by name and not positionally' );

// AC2: a release carrying only a foreign asset — no name match — must resolve to
// no update at all, never positionally to the first asset or the source ZIP.
$fake_release = [
	'tag_name'    => 'v99.0.0',
	'created_at'  => '2099-01-01T00:00:00Z',
	'zipball_url' => 'https://api.github.com/repos/Kntnt/kntnt-extractor/zipball/v99.0.0',
	'assets'      => [
		[
			'name'                 => 'some-other-plugin.zip',
			'url'                  => 'https://api.github.com/repos/Kntnt/kntnt-extractor/releases/assets/1',
			'browser_download_url' => 'https://github.com/Kntnt/kntnt-extractor/releases/download/v99.0.0/some-other-plugin.zip',
			'download_count'       => 0,
		],
	],
];
$resolved_without_asset = $api !== null ? $api->chooseReference( 'master' ) : 'unset';
kntnt_extractor_assert( $api !== null && $resolved_without_asset === null, 'AC2: a release with no name-matching asset yields no update source' );

remove_filter( 'pre_http_request', $intercept, 10 );

// AC2/AC4: the bootstrap already registered the update checker, so PUC hooks the
// plugin-update transient and an available update shows on the Plugins screen —
// the update installs in place, with no manual file replacement.
kntnt_extractor_assert( has_filter( 'site_transient_update_plugins' ) !== false, 'AC2/AC4: an available update is advertised through the plugin-update transient' );
