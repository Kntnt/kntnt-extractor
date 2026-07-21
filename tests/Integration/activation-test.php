<?php
/**
 * Integration test: the plugin activates and deactivates cleanly.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

// The main file loaded and the hand-written PSR-4 autoloader resolved the
// plugin's namespaced classes.
kntnt_extractor_assert( class_exists( \Kntnt\Extractor\Plugin::class ), 'Autoloader resolves the plugin classes' );

// The plugin is active after the bootstrap activated it – a clean activation.
$plugin = 'kntnt-extractor/kntnt-extractor.php';
kntnt_extractor_assert( is_plugin_active( $plugin ), 'Plugin activates cleanly' );

// Deactivation leaves no active trace; re-activate so later tests keep an active
// plugin regardless of file order.
deactivate_plugins( $plugin );
kntnt_extractor_assert( ! is_plugin_active( $plugin ), 'Plugin deactivates cleanly' );
activate_plugin( $plugin );
