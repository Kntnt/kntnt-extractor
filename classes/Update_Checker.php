<?php
/**
 * Self-hosted update checker: wires the bundled library to the plugin's own
 * GitHub releases.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\BaseChecker;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi;

/**
 * Advertises new plugin releases from GitHub on the WordPress Plugins screen.
 *
 * The plugin has no WordPress.org listing (ADR-0005), so without a self-hosted
 * update checker a new release would never surface on the Plugins screen and
 * every upgrade would mean a manual file replacement — defeating the "install
 * and forget" promise that choosing a regular plugin was meant to keep
 * (ADR-0001). This class bundles the YahnisElsts Plugin Update Checker, points
 * it at this repository's releases, and constrains it to the one release asset
 * selected BY NAME. Matching by name — never by the asset's position in the
 * release, and never falling back to GitHub's auto-generated source archive —
 * is what keeps self-update aligned with the version-less archive
 * build-release-zip.sh publishes.
 *
 * @since 0.1.0
 */
final class Update_Checker {

	/**
	 * The GitHub repository whose releases feed the update checker.
	 *
	 * @since 0.1.0
	 */
	public const string REPOSITORY_URL = 'https://github.com/Kntnt/kntnt-extractor/';

	/**
	 * The plugin slug, reused as the update checker's bookkeeping key.
	 *
	 * @since 0.1.0
	 */
	public const string SLUG = 'kntnt-extractor';

	/**
	 * The exact release-asset filename build-release-zip.sh publishes.
	 *
	 * The updater selects the release asset by matching this name, so this
	 * constant and the build script are the two ends of one contract: were they
	 * to drift, self-update would silently break. The name carries no version
	 * segment, so a release's asset URL stays stable across versions.
	 *
	 * @since 0.1.0
	 */
	public const string ASSET_NAME = 'kntnt-extractor.zip';

	/**
	 * The built update checker, memoised so registration is build-once.
	 *
	 * Constructing a second Plugin Update Checker for the same slug within one
	 * request collides on the library's per-slug bookkeeping and aborts the
	 * request, so register() builds exactly once and returns that instance
	 * thereafter. The plugin has a single update source, so one shared instance
	 * is the whole truth.
	 *
	 * @since 0.1.0
	 *
	 * @var object|null
	 */
	private static ?object $checker = null;

	/**
	 * Binds the checker to the main plugin file it reads the installed version from.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 */
	public function __construct( private readonly string $plugin_file ) {}

	/**
	 * Builds and registers the update checker against the plugin's releases.
	 *
	 * Loads the bundled library on demand, points it at REPOSITORY_URL, and
	 * enables release assets filtered to ASSET_NAME with a *required* preference:
	 * a release without a name-matching asset yields no update at all, rather
	 * than a positionally-chosen wrong package or the source ZIP. Returns the
	 * configured checker so the wiring can be inspected.
	 *
	 * @since 0.1.0
	 *
	 * @return object The configured Plugin Update Checker instance.
	 */
	public function register(): object {

		// Build exactly once per request: a second checker for the same slug
		// collides on the library's per-slug bookkeeping and aborts the request.
		if ( self::$checker !== null ) {
			return self::$checker;
		}

		// Load the bundled library on demand; it registers its own autoloader.
		require_once __DIR__ . '/../lib/plugin-update-checker/plugin-update-checker.php';

		// Point the checker at this plugin's own GitHub releases.
		$checker = PucFactory::buildUpdateChecker( self::REPOSITORY_URL, $this->plugin_file, self::SLUG );

		// Constrain the GitHub API to the by-name release asset: select the
		// package by ASSET_NAME, never positionally, and refuse to fall back to
		// GitHub's auto-generated source ZIP when no matching asset exists. The
		// instanceof narrowings resolve the factory's deliberately broad return
		// type to the concrete GitHub API; the pinned v5p7 references track the
		// bundled library version.
		$api = $checker instanceof BaseChecker ? $checker->getVcsApi() : null;
		if ( $api instanceof GitHubApi ) {
			$api->enableReleaseAssets( self::asset_name_pattern(), GitHubApi::REQUIRE_RELEASE_ASSETS );
		}

		self::$checker = $checker;

		return $checker;

	}

	/**
	 * The anchored regular expression that matches the release asset by name.
	 *
	 * @since 0.1.0
	 *
	 * @return string A PCRE matching exactly ASSET_NAME.
	 */
	public static function asset_name_pattern(): string {
		return '/^' . preg_quote( self::ASSET_NAME, '/' ) . '$/';
	}

}
