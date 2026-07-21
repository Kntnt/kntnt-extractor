<?php
/**
 * Integration test: the Config seam resolves a constant, overridable by a filter.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

$config = new \Kntnt\Extractor\Config();

// With neither constant nor filter set, the caller's default is returned.
kntnt_extractor_assert( $config->get( 'demo_knob', 'default-value' ) === 'default-value', 'Config falls back to the default when unset' );

// A defined constant supplies the value.
define( 'KNTNT_EXTRACTOR_DEMO_KNOB', 'from-constant' );
kntnt_extractor_assert( $config->get( 'demo_knob', 'default-value' ) === 'from-constant', 'Config reads the value from its constant' );

// A filter overrides the constant – the filter always wins.
add_filter( 'kntnt_extractor_config_demo_knob', static fn(): string => 'from-filter' );
kntnt_extractor_assert( $config->get( 'demo_knob', 'default-value' ) === 'from-filter', 'Config lets a filter override the constant (filter wins)' );
