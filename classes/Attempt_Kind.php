<?php
/**
 * The kind of packaging chunk one attempt began.
 *
 * @package Kntnt\Extractor
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * Closed set of chunk kinds an attempt log entry may name (ADR-0016).
 *
 * Matches the builder's order — full-data tables, then structure-only tables,
 * then files, then the sealed index — so a reader can tell which resource a
 * begun attempt was packaging without storing any path on the state file.
 *
 * @since 0.6.0
 */
enum Attempt_Kind: string {

	case Table = 'table';
	case Structure = 'structure';
	case File = 'file';
	case Index = 'index';

}
