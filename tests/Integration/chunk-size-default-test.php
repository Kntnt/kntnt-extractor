<?php
/**
 * Integration test: the shipped chunk budgets are the measured defaults.
 *
 * A file-part default that no host had ever actually produced a full-size part at
 * is what failed a 74-minute production run outright, and what later cost a
 * six-hour one 44 % of its wall clock on three files. The replacement is read off
 * a committed measurement rather than chosen, so it is pinned here rather than
 * left to a reader of the constant (ADR-0023).
 *
 * It pins what issue #27 decided:
 *  - AC1: with neither constant, filter, nor per-job budget set, the file-part
 *    bound the driver actually reads is 262,144 bytes — the fastest size in
 *    `docs/measurements/2026-08-19-chunk-size-curve.md`, and the only one
 *    `docs/measurements/2026-08-19-successful-run.md` watched complete a clone.
 *  - AC2: the two table-slice bounds are untouched by that decision, which moved
 *    the file-part bound alone.
 *  - AC3: a site's own knob still wins over the new default, since the right
 *    value is host-specific and this one is only the value one host measured.
 *
 * The assertions read `Artifact_Builder::budgets()`, which is the single seam the
 * tick driver resolves all three bounds through — the lowest layer at which the
 * shipped default is observable at all.
 *
 * @package Kntnt\Extractor
 * @since   0.6.1
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Artifact_Builder;
use Kntnt\Extractor\Config;
use Kntnt\Extractor\Extraction_Job;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Table_Dumper;

// A job that has never stalled — every budget zero — so each bound falls through
// to the compiled-in default rather than to a size a stall adapted down to.
$defaults_builder = new Artifact_Builder( new Table_Dumper(), new Config() );
$unadapted_job = new Extraction_Job( 'chunk-size-default', Job_State::Queued, 0, '', [], [], [], time(), time(), '', '' );
$default_budgets = $defaults_builder->budgets( $unadapted_job );

// AC1: the file-part default is the measured 256 KB, not a chosen round number.
kntnt_extractor_assert( $default_budgets->file_bytes === 262144, 'The shipped file-part budget is the measured 256 KB / 262,144 bytes (AC1)' );

// AC2: the table slice's two bounds are not what this decision moved, so a
// silent drift in either would be a change nothing measured.
kntnt_extractor_assert( $default_budgets->table_bytes === 4194304, 'The shipped table-slice byte budget is unchanged at 4 MiB (AC2)' );
kntnt_extractor_assert( $default_budgets->table_rows === 1000, 'The shipped table-slice row budget is unchanged at 1000 rows (AC2)' );

// AC3: the default is one host's measurement, so a site that has measured its own
// must still be able to overrule it through the Config seam.
$force_chunk_size = static fn(): int => 4096;
add_filter( 'kntnt_extractor_config_chunk_size', $force_chunk_size );
$configured_budgets = $defaults_builder->budgets( $unadapted_job );
remove_filter( 'kntnt_extractor_config_chunk_size', $force_chunk_size );
kntnt_extractor_assert( $configured_budgets->file_bytes === 4096, 'A site knob still overrides the shipped file-part default (AC3)' );
