<?php
/**
 * Uninstall cleanup: erase every sensitive on-disk residue when the plugin is removed.
 *
 * WordPress runs this file in a fresh request with the plugin not loaded, so it
 * loads the plugin's own autoloader and delegates to the testable
 * {@see \Kntnt\Extractor\Uninstaller} — keeping the logic reachable from tests
 * rather than trapped behind the `WP_UNINSTALL_PLUGIN` guard (issue #13,
 * ADR-0006/0008).
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Refuse to run unless WordPress invoked this file through the sanctioned
// uninstall path; a direct request never defines this constant.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the plugin's own PSR-4 autoloader — the plugin's classes are not loaded
// during uninstall — then delegate to the testable cleanup routine.
require_once __DIR__ . '/autoloader.php';

\Kntnt\Extractor\Uninstaller::purge_all();
