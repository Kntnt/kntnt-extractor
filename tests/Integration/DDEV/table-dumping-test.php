<?php
/**
 * MySQL-backed check that Table_Dumper's engine-specific SQL is correct, not merely
 * whatever SQLite translates it into.
 *
 * Run inside a booted WordPress by `wp eval-file` (see run.sh), against a real
 * MySQL/InnoDB database. `Table_Dumper` is the plugin's most MySQL-specific code:
 * it reads `SHOW KEYS` for the primary key's own column order, builds a
 * lexicographic keyset predicate to page without `OFFSET`, falls back to
 * `LIMIT`/`OFFSET` on a keyless table, and emits `SHOW CREATE TABLE` DDL. The fast
 * Playground suite (`tests/Integration/table-chunking-test.php`) exercises the same
 * acceptance criteria, but every statement there reaches the database through
 * SQLite's translation layer — this file re-establishes them against the engine
 * the plugin actually runs on in production, plus the one assertion only a real
 * engine can make: that the dumped SQL reloads into a row-for-row identical table.
 *
 * Four fixture tables cover the primary-key shapes a real site presents: a
 * single-column key, a composite key (the shape WordPress core ships on
 * `wp_term_relationships`), no key at all, and a few fat rows whose byte weight
 * — not their row count — is what forces a slice to split. `Table_Dumper` is
 * driven directly through `dump_chunk()`, the level its slicing behaviour lives
 * at, rather than through the REST/job layer this concern does not need.
 *
 * Prints a TAP-style line per assertion and exits non-zero if any fails, so the
 * runner turns red on failure.
 *
 * @package Kntnt\Extractor
 * @since   0.6.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Table_Dumper;

/**
 * The WordPress database access layer.
 *
 * @var \wpdb $wpdb
 */
global $wpdb;

// Minimal TAP assertion helper: record each check and remember any failure so
// the process can exit non-zero for the runner.
$failed = 0;
$assert = static function ( bool $passed, string $description ) use ( &$failed ): void {
	printf( "%s - %s\n", $passed ? 'ok' : 'not ok', $description );
	if ( ! $passed ) {
		++$failed;
	}
};

// Renders a scalar or null as a SQL literal for building fixture INSERTs. Mirrors
// how Table_Dumper itself treats a value — NULL unquoted, everything else an
// escaped string — because the fixture's own seeding has to reach MySQL through
// the same rules the code under test will read it back through.
$literal = static function ( mixed $value ) use ( $wpdb ): string {
	return $value === null ? 'NULL' : $wpdb->prepare( '%s', $value );
};

// Drives dump_chunk() to completion under a forced row and byte budget, feeding
// each returned cursor into the next call. This is the level the slicing
// behaviour lives at, independent of job records and the chunk-budget plumbing.
$dumper = new Table_Dumper();
$drive_dump = static function ( string $table, int $max_rows, int $max_bytes ) use ( $dumper ): array {
	$sql = '';
	$rows_done = 0;
	$cursor = null;
	$slices = 0;
	do {
		[ $part, $cursor, $rows_done, $complete ] = $dumper->dump_chunk( $table, $cursor, $rows_done, $max_rows, $max_bytes );
		$sql .= $part;
		$slices++;
	} while ( ! $complete && $slices < 1000 );
	return [ $sql, $rows_done, $slices ];
};

// The unchunked reference: the same table rendered in a single slice, under
// budgets neither of which can bite. The byte-identical-concatenation checks and
// the reload check both compare against this.
$whole_dump = static fn( string $table ): string => $dumper->dump_chunk( $table, null, 0, 1000000, PHP_INT_MAX )[0];

// Executes a multi-statement mysqldump-style SQL string one statement at a time,
// because $wpdb->query() (mysqli_query) runs exactly one statement per call. Every
// statement Table_Dumper emits ends in ";\n" and no escaped value ever contains an
// unescaped newline byte (literal() turns a real newline into the two characters
// "\n"), so splitting on the literal sequence ";\n" never lands inside a value.
$run_sql = static function ( string $sql ) use ( $wpdb ): void {
	foreach ( explode( ";\n", $sql ) as $statement ) {
		$statement = trim( $statement );
		if ( $statement !== '' ) {
			$wpdb->query( $statement ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- replaying a dump this file built and controls end to end, against a fixture table only this file owns.
		}
	}
};

// --- Fixtures: the primary-key shapes a real site presents ---

$fixture_rows = 250;
$slice_rows = 100;

$single_table = 'kntnt_extractor_dump_single';
$composite_table = 'kntnt_extractor_dump_composite';
$keyless_table = 'kntnt_extractor_dump_keyless';
$fat_table = 'kntnt_extractor_dump_fat';
$reload_table = 'kntnt_extractor_dump_single_reload';

$wpdb->query( "DROP TABLE IF EXISTS `{$single_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$composite_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$keyless_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$fat_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$reload_table}`" );

// Fixture 1: a single-column primary key, carrying a DEFAULT, a NOT NULL, and a
// secondary index besides the primary key, so SHOW CREATE TABLE has something
// real to render. Its `note` column cycles through the values that only a real
// engine escapes correctly: NULL, an empty string, a single quote, a backslash, a
// newline, a multi-byte UTF-8 string, and a numeric-looking string.
$wpdb->query( "CREATE TABLE `{$single_table}` ( `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `tag` varchar(40) NOT NULL DEFAULT '', `note` varchar(255) NULL, PRIMARY KEY (`id`), KEY `tag_idx` (`tag`) )" );

// Fixture 2: a composite primary key, the shape wp_term_relationships ships on
// core. The second column varies within a repeated first column, which is what
// discriminates a keyset seeked on all key columns from one seeked on only the
// first — the fixture the plan calls out as the one most likely to regress.
$wpdb->query( "CREATE TABLE `{$composite_table}` ( `a` bigint(20) NOT NULL, `b` bigint(20) NOT NULL, `tag` varchar(40) NOT NULL, PRIMARY KEY (`a`,`b`) )" );

// Fixture 3: no primary key at all, forcing the LIMIT/OFFSET fallback.
$wpdb->query( "CREATE TABLE `{$keyless_table}` ( `n` bigint(20) NOT NULL, `tag` varchar(40) NOT NULL )" );

// Fixture 4: few fat rows, so the byte budget cuts a page short well inside the
// row budget — the shape a row budget alone cannot see.
$wpdb->query( "CREATE TABLE `{$fat_table}` ( `id` bigint(20) NOT NULL, `payload` longtext NOT NULL, PRIMARY KEY (`id`) )" );

// Seed fixtures 1-3 with 250 rows each: enough to need several slices under the
// 100-row budget forced below. The composite key's (a, b) rises lexicographically
// with the marker, thirteen b-values per a-value. Thirteen is deliberate: it does
// NOT divide the 100-row slice budget evenly, so a slice boundary is guaranteed to
// fall mid-group rather than landing tidily on an `a` boundary every time — the
// layout a keyset seeked on only the first column would fail to notice if the
// groups always split cleanly at a page edge.
$awkward = [ null, '', "O'Brien", 'back\\slash', "line\nbreak", 'héllo wörld 日本語 😀', '0123' ];
$single_values = [];
$composite_values = [];
$keyless_values = [];
$composite_group = 13;
for ( $i = 1; $i <= $fixture_rows; $i++ ) {
	$a = intdiv( $i - 1, $composite_group ) + 1;
	$b = ( $i - 1 ) % $composite_group + 1;
	$note = $awkward[ ( $i - 1 ) % count( $awkward ) ];
	$single_values[] = '(' . $i . ',' . $literal( "SINGLEMARK-{$i}" ) . ',' . $literal( $note ) . ')';
	$composite_values[] = "({$a},{$b}," . $literal( "COMPOSITEMARK-{$i}" ) . ')';
	$keyless_values[] = '(' . $i . ',' . $literal( "KEYLESSMARK-{$i}" ) . ')';
}
$wpdb->query( "INSERT INTO `{$single_table}` (`id`, `tag`, `note`) VALUES " . implode( ',', $single_values ) );
$wpdb->query( "INSERT INTO `{$composite_table}` (`a`, `b`, `tag`) VALUES " . implode( ',', $composite_values ) );
$wpdb->query( "INSERT INTO `{$keyless_table}` (`n`, `tag`) VALUES " . implode( ',', $keyless_values ) );

// Seed the fat fixture: 20 rows of roughly 50 KB, far inside any row budget and
// still too wide for a single byte-bounded slice.
$fat_rows = 20;
$fat_width = 51200;
$fat_values = [];
for ( $i = 1; $i <= $fat_rows; $i++ ) {
	$fat_values[] = '(' . $i . ',' . $literal( "FATMARK-{$i}-" . str_repeat( 'x', $fat_width ) ) . ')';
}
$wpdb->query( "INSERT INTO `{$fat_table}` (`id`, `payload`) VALUES " . implode( ',', $fat_values ) );
$fat_bytes = 4 * $fat_width;

// --- Acceptance criterion 1: every row exactly once ---

// The keyed fixtures: compare the SET of primary-key values the dump emits
// against the set the table actually holds — a count alone would not catch a row
// emitted twice while another was skipped.
foreach (
	[
		$single_table => [ '/\(\'(\d+)\',/', 'id' ],
		$composite_table => [ '/\(\'(\d+)\',\'(\d+)\',/', null ],
		$fat_table => [ '/\(\'(\d+)\',/', 'id' ],
	] as $table => [ $pattern, $id_column ]
) {
	[ $sql ] = $drive_dump( $table, $slice_rows, PHP_INT_MAX );
	preg_match_all( $pattern, $sql, $found, PREG_SET_ORDER );
	$emitted_keys = array_map( static fn( array $m ): string => implode( ',', array_slice( $m, 1 ) ), $found );
	if ( $id_column !== null ) {
		$actual_keys = array_map( 'strval', $wpdb->get_col( "SELECT `{$id_column}` FROM `{$table}`" ) );
	} else {
		$actual_keys = array_map(
			static fn( array $row ): string => "{$row['a']},{$row['b']}",
			$wpdb->get_results( "SELECT `a`, `b` FROM `{$composite_table}`", ARRAY_A ),
		);
	}
	sort( $emitted_keys );
	sort( $actual_keys );
	$assert( $emitted_keys === $actual_keys, "Every primary-key value of {$table} is dumped exactly once, matching the table's own set (AC1)" );
}

// --- Acceptance criterion 2: key order ---

// A keyed table's rows come back in its key's own declared order. For the
// single-column key this is a plain ascending comparison; for the composite key
// it doubles as criterion 3, checked next.
[ $single_sql ] = $drive_dump( $single_table, $slice_rows, PHP_INT_MAX );
preg_match_all( '/SINGLEMARK-(\d+)/', $single_sql, $single_found );
$single_emitted_ids = array_map( 'intval', $single_found[1] );
$assert( $single_emitted_ids === range( 1, $fixture_rows ), 'The single-column-key table is emitted in ascending primary-key order across every slice (AC2)' );

// --- Acceptance criterion 3: the composite key is paged on ALL its columns ---

// If the seek predicate compared only the first column, rows sharing an `a` value
// would be skipped or repeated across a slice boundary as soon as one page ended
// mid-group — exactly the shape this fixture's thirteen-rows-per-`a` layout forces.
[ $composite_sql ] = $drive_dump( $composite_table, $slice_rows, PHP_INT_MAX );
preg_match_all( '/COMPOSITEMARK-(\d+)/', $composite_sql, $composite_found );
$composite_emitted = array_map( 'intval', $composite_found[1] );
$assert( $composite_emitted === range( 1, $fixture_rows ), 'The composite-key table is emitted in its full (a, b) key order across every slice — a seek on only the first column would skip or repeat rows here (AC3)' );

// --- Acceptance criterion 4: concatenation is byte-identical to a single slice ---

// This holds for a ROW-bounded cut only: it is rounded up to a whole extended-INSERT
// batch, so the boundary is always a statement boundary (Table_Dumper's own class
// docblock). A BYTE-bounded cut is documented to land mid-batch instead — "the price
// of the slice being bounded in the unit the host actually enforces" — so the fat
// fixture, whose slices below are cut by bytes, is deliberately not compared here.
foreach ( [ $single_table, $composite_table, $keyless_table ] as $table ) {
	[ $chunked_sql ] = $drive_dump( $table, $slice_rows, PHP_INT_MAX );
	$assert( $chunked_sql === $whole_dump( $table ), "The row-chunked dump of {$table} is byte-identical to the same table dumped in a single slice (AC4)" );
}

// --- Acceptance criterion 5: a byte-cut page is not the end, and a slice always renders at least one row ---

[ , , $fat_first_rows, $fat_first_complete ] = $dumper->dump_chunk( $fat_table, null, 0, 1000, $fat_bytes );
$assert( $fat_first_rows > 0 && $fat_first_rows < $fat_rows, "A slice renders only the rows its byte budget holds, though its row budget is untouched (AC5)" );
$assert( $fat_first_complete === false, 'A page cut short by the byte budget is not reported as the end of the table (AC5)' );

[ , , $fat_min_rows ] = $dumper->dump_chunk( $fat_table, null, 0, 1000, 1 );
$assert( $fat_min_rows === 1, 'A slice always renders at least one row, however small the byte budget (AC5)' );

// --- Acceptance criterion 6: the keyless fixture completes via the offset walk ---

[ $keyless_sql, $keyless_rows_done, $keyless_slices ] = $drive_dump( $keyless_table, $slice_rows, PHP_INT_MAX );
$assert( $keyless_slices > 1, 'The keyless table needs more than one slice under the forced row budget, so the offset walk is genuinely exercised (AC6)' );
$assert( $keyless_rows_done === $fixture_rows, 'The keyless table completes via the offset walk, having rendered every row (AC6)' );
preg_match_all( '/KEYLESSMARK-(\d+)/', $keyless_sql, $keyless_found );
$assert( count( $keyless_found[0] ) === $fixture_rows && count( array_unique( $keyless_found[0] ) ) === $fixture_rows, 'The keyless table emits every row exactly once, with no skips or duplicates (AC6)' );

// --- Acceptance criterion 7: the dump reloads, and the reload matches the original ---

// This is the assertion only a real engine can make: that the emitted SQL is not
// merely well-formed, but actually restores a row-for-row identical table. Done
// on a copy of the single-column-key table — the fixture carrying the awkward
// escaping values — so the original is left untouched for any assertion above
// that runs after this one.
$reload_sql = str_replace( "`{$single_table}`", "`{$reload_table}`", $whole_dump( $single_table ) );
$run_sql( $reload_sql );
$original_rows = $wpdb->get_results( "SELECT * FROM `{$single_table}` ORDER BY `id`", ARRAY_A );
$reloaded_rows = $wpdb->get_results( "SELECT * FROM `{$reload_table}` ORDER BY `id`", ARRAY_A );
$assert( is_array( $reloaded_rows ) && count( $reloaded_rows ) === $fixture_rows, 'The reload recreates the table and repopulates every row (AC7)' );
$assert( $original_rows === $reloaded_rows, 'The reloaded table is row-for-row identical to the original, including the NULL, empty, quoted, backslashed, newline-carrying, multi-byte, and numeric-looking values (AC7)' );

// Leave no fixture behind, whatever the outcome above.
$wpdb->query( "DROP TABLE IF EXISTS `{$single_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$composite_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$keyless_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$fat_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$reload_table}`" );

if ( $failed > 0 ) {
	fwrite( STDERR, sprintf( "DDEV table dumping check: %d assertion(s) failed\n", $failed ) );
	exit( 1 );
}
echo "DDEV table dumping check: PASS\n";
