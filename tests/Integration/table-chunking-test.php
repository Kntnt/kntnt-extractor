<?php
/**
 * Integration test: chunked, resumable table dumping (ADR-0013).
 *
 * A table used to be packaged whole, in one tick, from one unbounded `SELECT *`.
 * On a production site that made every table above the host's per-request ceiling
 * unextractable: the tick was killed mid-dump, the watchdog restarted it, and the
 * job reported `running` with a frozen table counter forever without ever failing.
 * This file drives the fix end to end against the live REST server, on fixture
 * tables built to exercise the three primary-key shapes a real site has.
 *
 * It pins:
 *  - AC1: a table larger than one slice is packaged as several independently-sealed
 *    segments across several ticks, each recorded in the sealed index under the
 *    table's own name, exactly as an oversized file's parts are.
 *  - AC2: the segments reassemble to SQL byte-identical to the same table dumped in
 *    a single slice, so chunking changes where the SQL is cut and nothing else.
 *  - AC3: every row is carried exactly once — no skips, no duplicates — across a
 *    single-column primary key, a composite primary key, and no primary key at all,
 *    and a keyed table's rows come back in its key's own order.
 *  - AC4: interrupting a job mid-table and resuming keeps the committed prefix, so a
 *    completed slice is never redone or re-encrypted, and the result is still
 *    byte-identical. The persisted progress carries the mid-table row offset and
 *    keyset cursor that make that possible.
 *  - AC5: a chunk that keeps dying where no `catch` can see it first shrinks its
 *    byte budget and keeps going; only a budget already at one byte fails the job,
 *    with a reason naming the table and row it stalled on. A job that can still
 *    progress is left to progress and has its attempt counter cleared by every
 *    real advance.
 *  - AC6: a job record written before this change is no longer a shape this
 *    release understands (ADR-0024), and says so quietly — a 404 rather than a
 *    throw, and the record left exactly where it lies.
 *  - AC7: a slice is bounded by bytes as well as by rows, so a table of few fat rows
 *    — the shape that sits inside any row budget and still cannot be packaged in one
 *    slice — splits rather than stalls, and a page the byte budget cut short is never
 *    mistaken for the end of the table.
 *  - AC8: the poll's `chunks_done` advances on every packaging chunk, so a job working
 *    steadily through one large table is distinguishable from a wedged one — which the
 *    four whole-resource counters alone cannot do.
 *  - AC9: a table's two catalog facts — that the name exists, and which columns page it
 *    — are read once per request rather than once per slice, and the memo holding them
 *    is an optimisation and never an authority: a miss re-reads the live catalog before
 *    anything is accepted or refused. The cross-tick guard the key memo narrows is
 *    pinned alongside it.
 *
 * @package Kntnt\Extractor
 * @since   0.4.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Table_Dumper;
use Kntnt\Extractor\Crypto\Sealed_Writer;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

// Recursively removes a directory tree so the suite leaves no working directory
// behind on the host.
$rmrf = static function ( string $dir ) use ( &$rmrf ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: [] as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			$rmrf( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
};

// Dispatches POST /extractions with a JSON body through the live REST server.
$post_extractions = static function ( array $body ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $request );
};

// Dispatches GET /extractions/{id} through the live REST server.
$get_extraction = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions/' . $id ) );
};

// Dispatches POST /extractions/{id}/tick carrying the per-job secret.
$tick = static function ( string $id, string $secret ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

// Reads a length-prefixed 64-bit-LE field at $offset, advancing it.
$read_length = static function ( string $raw, int &$offset ): int {
	$value = unpack( 'P', substr( $raw, $offset, 8 ) )[1];
	$offset += 8;
	return (int) $value;
};

// Parses a finished sealed container into its segment records and sealed index —
// the independent reader a downloading client would implement over the wire format.
$parse = static function ( string $raw ) use ( $read_length ): array {
	$magic = Sealed_Writer::MAGIC;
	$header_len = strlen( $magic ) + 1;
	$trailer_at = strlen( $raw ) - 8;
	$index_len = (int) unpack( 'P', substr( $raw, $trailer_at, 8 ) )[1];
	$sealed_index = substr( $raw, $trailer_at - $index_len, $index_len );
	$body_end = $trailer_at - $index_len;

	$records = [];
	$offset = $header_len;
	while ( $offset < $body_end ) {
		$sk_len = $read_length( $raw, $offset );
		$sealed_key = substr( $raw, $offset, $sk_len );
		$offset += $sk_len;
		$nonce = substr( $raw, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$offset += SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		$ct_len = $read_length( $raw, $offset );
		$ciphertext = substr( $raw, $offset, $ct_len );
		$offset += $ct_len;
		$records[] = [ 'sealed_key' => $sealed_key, 'nonce' => $nonce, 'ciphertext' => $ciphertext ];
	}

	return [ 'sealed_index' => $sealed_index, 'records' => $records ];
};

// Recovers a segment's plaintext with the caller's keypair, or false on failure.
$open_segment = static function ( array $record, string $keypair ): string|false {
	$key = sodium_crypto_box_seal_open( $record['sealed_key'], $keypair );
	if ( $key === false ) {
		return false;
	}
	return sodium_crypto_secretbox_open( $record['ciphertext'], $record['nonce'], $key );
};

// Unseals and splits the length-prefixed index back into its ordered names.
$open_index = static function ( string $sealed_index, string $keypair ): ?array {
	$plain = sodium_crypto_box_seal_open( $sealed_index, $keypair );
	if ( $plain === false ) {
		return null;
	}
	$names = [];
	$offset = 0;
	while ( $offset < strlen( $plain ) ) {
		$len = (int) unpack( 'P', substr( $plain, $offset, 8 ) )[1];
		$offset += 8;
		$names[] = substr( $plain, $offset, $len );
		$offset += $len;
	}
	return $names;
};

// Reads a job's persisted state file into a decoded array, or null when absent.
$read_state = static function ( string $work, string $id ): ?array {
	$path = $work . '/' . $id . '/state.json';
	$decoded = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $decoded ) ? $decoded : null;
};

// Writes a decoded record back over a job's state file verbatim — the escape hatch
// that lets a test plant an attempt count no successful run would ever produce.
$write_state = static function ( string $work, string $id, array $data ): void {
	file_put_contents( $work . '/' . $id . '/state.json', (string) wp_json_encode( $data ) );
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree still under uploads, so the
// artifact stays web-reachable while the run owns all of its state and cleans up.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-chunk-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so the several jobs this file needs can coexist.
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Force a small row budget through the Config knob, so the fixture tables below
// split into several bounded slices and the whole run exercises the resumed paths.
$slice_rows = 100;
$force_slice = static fn(): int => $slice_rows;
add_filter( 'kntnt_extractor_config_table_chunk_rows', $force_slice );

// Short-circuit every loopback the driver fires, so a nudge never touches the real
// network; the ticks below drive each job synchronously across chunks.
$intercept = static fn( $pre, $args, $url ) => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

// Three fixture tables, one per primary-key shape a real site presents: the ordinary
// single-column key, the composite key WordPress itself ships on wp_term_relationships,
// and the keyless table a plugin occasionally creates. Each holds more rows than one
// slice carries, so each must be dumped across several segments. The rows carry a
// distinct marker per table so coverage can be counted without ambiguity.
$fixture_rows = 250;
$single_table = 'kntnt_extractor_chunk_single';
$composite_table = 'kntnt_extractor_chunk_composite';
$keyless_table = 'kntnt_extractor_chunk_keyless';
$wpdb->query( "DROP TABLE IF EXISTS `{$single_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$composite_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$keyless_table}`" );
$wpdb->query( "CREATE TABLE `{$single_table}` ( `id` bigint(20) NOT NULL, `tag` varchar(40) NOT NULL, PRIMARY KEY (`id`) )" );
$wpdb->query( "CREATE TABLE `{$composite_table}` ( `a` bigint(20) NOT NULL, `b` bigint(20) NOT NULL, `tag` varchar(40) NOT NULL, PRIMARY KEY (`a`,`b`) )" );
$wpdb->query( "CREATE TABLE `{$keyless_table}` ( `n` bigint(20) NOT NULL, `tag` varchar(40) NOT NULL )" );

// Seed each table. The composite key's rows are ordered so that (a, b) rises
// lexicographically while the marker number rises with them, which is what lets the
// emitted order be checked against the index's own order further down.
$single_values = [];
$composite_values = [];
$keyless_values = [];
for ( $i = 1; $i <= $fixture_rows; $i++ ) {
	$a = intdiv( $i - 1, 10 ) + 1;
	$b = ( $i - 1 ) % 10 + 1;
	$single_values[] = "({$i}, 'SINGLEMARK-{$i}')";
	$composite_values[] = "({$a}, {$b}, 'COMPOSITEMARK-{$i}')";
	$keyless_values[] = "({$i}, 'KEYLESSMARK-{$i}')";
}
$wpdb->query( "INSERT INTO `{$single_table}` (`id`, `tag`) VALUES " . implode( ',', $single_values ) );
$wpdb->query( "INSERT INTO `{$composite_table}` (`a`, `b`, `tag`) VALUES " . implode( ',', $composite_values ) );
$wpdb->query( "INSERT INTO `{$keyless_table}` (`n`, `tag`) VALUES " . implode( ',', $keyless_values ) );

// The number of slices each fixture must split into under the forced row budget —
// the property AC1 turns on: a whole-table segment would be exactly one.
$expected_slices = (int) ceil( $fixture_rows / $slice_rows );

// --- AC9: the catalog facts are read once per request, by a memo that re-reads ---

// This section drives the dumper directly and runs ahead of the REST-driven cases
// below deliberately. A memo that refused on a miss would break the one dumper the
// plugin shares across this whole process, and this file would then abort on the
// empty artifact of a failed job long before any assertion about the memo itself
// was reached — so the assertions that discriminate between the two designs have to
// stand where a run under the wrong one can still emit them.

// Counts the two catalog round trips a stretch of dumping issues. Every statement
// $wpdb runs passes the `query` filter, which is what lets these round trips be
// pinned by a test where the filesystem ones of the same family could not be.
$counts = [ 'tables' => 0, 'keys' => 0 ];
$probe = static function ( string $query ) use ( &$counts ): string {
	if ( str_starts_with( $query, 'SHOW TABLES' ) ) {
		++$counts['tables'];
	}
	if ( str_starts_with( $query, 'SHOW KEYS' ) ) {
		++$counts['keys'];
	}
	return $query;
};

// Drives one dumper through a whole table under the probe and reports what the
// stretch cost: the SQL, the slices it took, and the two catalog counts. The filter
// comes off even when the dump throws, so a refusal never leaks a third global
// filter into every file that runs after this one.
$probe_dump = static function ( Table_Dumper $dumper, string $table ) use ( $probe, &$counts, $slice_rows ): array {
	$counts = [ 'tables' => 0, 'keys' => 0 ];
	$sql = '';
	$rows_done = 0;
	$cursor = null;
	$slices = 0;
	add_filter( 'query', $probe );
	try {
		do {
			[ $part, $cursor, $rows_done, $complete ] = $dumper->dump_chunk( $table, $cursor, $rows_done, $slice_rows, PHP_INT_MAX );
			$sql .= $part;
			$slices++;
		} while ( ! $complete && $slices < 500 );
	} finally {
		remove_filter( 'query', $probe );
	}
	return [ $sql, $slices, $counts['tables'], $counts['keys'] ];
};

// A fresh dumper, so every catalog read this stretch makes is its own. Each slice
// used to re-ask both questions; the saving is a round-trip count and nothing else,
// so a count is what pins it. The slice count is asserted in the same breath because
// one SHOW TABLES is also what a table that stopped being chunked would report.
$rt_dumper = new Table_Dumper();
[ , $rt_slices, $rt_tables, $rt_keys ] = $probe_dump( $rt_dumper, $single_table );
kntnt_extractor_assert( $rt_slices > 1 && $rt_tables === 1 && $rt_keys === 1, "A multi-slice dump asks the catalog once, not once per slice: {$rt_slices} slices, {$rt_tables} SHOW TABLES, {$rt_keys} SHOW KEYS (AC9)" );

// The harness failure in miniature, and the case the whole decision turns on: a
// table that did not exist when the memo was filled. The memo is an optimisation,
// so a miss re-reads and the table is dumped; a memo that refused on a miss would
// deny a table that is plainly there, which is a behaviour change nobody asked for.
$late_table = 'kntnt_extractor_chunk_late';
$wpdb->query( "DROP TABLE IF EXISTS `{$late_table}`" );
$wpdb->query( "CREATE TABLE `{$late_table}` ( `id` bigint(20) NOT NULL, `tag` varchar(40) NOT NULL, PRIMARY KEY (`id`) )" );
$late_values = [];
for ( $i = 1; $i <= $fixture_rows; $i++ ) {
	$late_values[] = "({$i}, 'LATEMARK-{$i}')";
}
$wpdb->query( "INSERT INTO `{$late_table}` (`id`, `tag`) VALUES " . implode( ',', $late_values ) );

$late_sql = '';
$late_slices = 0;
$late_tables = 0;
$late_refused = '';
try {
	[ $late_sql, $late_slices, $late_tables ] = $probe_dump( $rt_dumper, $late_table );
} catch ( Throwable $late_throwable ) {
	$late_refused = $late_throwable->getMessage();
}
preg_match_all( '/LATEMARK-\d+/', $late_sql, $late_found );
kntnt_extractor_assert( $late_refused === '' && count( $late_found[0] ) === $fixture_rows && count( array_unique( $late_found[0] ) ) === $fixture_rows, 'A table created after the memo was filled is still dumped, in full, through the same dumper (AC9)' );

// And the re-read that accepted it is a re-read, not a re-reading. Without this,
// "re-read on a miss" could degenerate into "re-read every slice" and give the whole
// saving back on any table whose name arrives after the first read.
kntnt_extractor_assert( $late_slices > 1 && $late_tables === 1, "That acceptance cost one listing, not one per slice: {$late_slices} slices, {$late_tables} SHOW TABLES (AC9)" );

// A refusal is never decided on stale knowledge either. With the memo warm, an
// unknown name is still refused — and refused against a listing this request took
// from the database AFTER the name was seen, which is strictly fresher than the
// listing the per-slice read could have taken before it.
$absent_table = 'kntnt_extractor_chunk_absent';
$absent_refused = '';
$counts = [ 'tables' => 0, 'keys' => 0 ];
add_filter( 'query', $probe );
try {
	$rt_dumper->dump_chunk( $absent_table, null, 0, $slice_rows, PHP_INT_MAX );
} catch ( Throwable $absent_throwable ) {
	$absent_refused = $absent_throwable->getMessage();
}
remove_filter( 'query', $probe );
kntnt_extractor_assert( $absent_refused === 'Refusing to dump a table that does not exist in the catalog.' && $counts['tables'] === 1, "An unknown table is still refused with the memo warm, on a listing read after the name was seen: {$counts['tables']} SHOW TABLES (AC9)" );

// --- AC9: the cross-tick guard the key memo narrows ---

// dump_chunk() refuses a cursor whose arity no longer matches the table's key,
// which is how a primary key that changed under a resume is caught. Memoising the
// key narrows that guard by one scope, so what remains needs pinning: a change
// between two TICKS, which is the guarantee production actually rests on, because
// every tick is a fresh request with a dumper of its own.
//
// What this does NOT pin, and no longer can: the same change between two slices of
// ONE tick. That case is outside the guard's reach once the key is memoised. It is
// not unprotected — a table recreated mid-dump fails the page read itself — but the
// arity check is no longer what catches it.
$key_table = 'kntnt_extractor_chunk_rekeyed';
$key_values = [];
for ( $i = 1; $i <= $fixture_rows; $i++ ) {
	$key_values[] = "({$i}, {$i})";
}
$wpdb->query( "DROP TABLE IF EXISTS `{$key_table}`" );
$wpdb->query( "CREATE TABLE `{$key_table}` ( `a` bigint(20) NOT NULL, `b` bigint(20) NOT NULL, PRIMARY KEY (`a`) )" );
$wpdb->query( "INSERT INTO `{$key_table}` (`a`, `b`) VALUES " . implode( ',', $key_values ) );

// One tick's worth: a slice paged by the single-column key, ending on a cursor of
// that key's arity. Without this the resume below would have nothing to disagree with.
$first_tick_dumper = new Table_Dumper();
[ , $key_cursor ] = $first_tick_dumper->dump_chunk( $key_table, null, 0, $slice_rows, PHP_INT_MAX );
kntnt_extractor_assert( is_array( $key_cursor ) && count( $key_cursor ) === 1, 'A slice of a single-column-keyed table ends on a cursor of that key arity (AC9)' );

// The key changes between the ticks. Dropping and recreating is what a real ALTER
// leaves behind and is portable across both engines the suite runs on.
$wpdb->query( "DROP TABLE IF EXISTS `{$key_table}`" );
$wpdb->query( "CREATE TABLE `{$key_table}` ( `a` bigint(20) NOT NULL, `b` bigint(20) NOT NULL, PRIMARY KEY (`a`,`b`) )" );
$wpdb->query( "INSERT INTO `{$key_table}` (`a`, `b`) VALUES " . implode( ',', $key_values ) );

// The next tick is a new request and therefore a new dumper, which is why it reads
// the changed key rather than the memoised one and refuses to seek an arity-1 cursor
// through it. Resuming anyway would silently skip or repeat rows.
$next_tick_dumper = new Table_Dumper();
$key_refused = '';
try {
	$next_tick_dumper->dump_chunk( $key_table, $key_cursor, $slice_rows, $slice_rows, PHP_INT_MAX );
} catch ( Throwable $key_throwable ) {
	$key_refused = $key_throwable->getMessage();
}
kntnt_extractor_assert( $key_refused === 'A table primary key changed while the table was being dumped.', 'A primary key that changed between two ticks still fails the resume (AC9)' );

// The unchunked reference: the same table rendered in a single slice, straight from
// the dumper, under budgets neither of which can bite. AC2 compares the reassembled
// multi-segment SQL against this.
$dumper = new Table_Dumper();
$whole_dump = static fn( string $table ): string => $dumper->dump_chunk( $table, null, 0, 1000000, PHP_INT_MAX )[0];

// A caller's ephemeral X25519 keypair; only the public half is submitted.
$keypair = sodium_crypto_box_keypair();
$public_key = sodium_crypto_box_publickey( $keypair );

$selection = [
	'tables' => [ $single_table, $composite_table, $keyless_table ],
	'public_key' => base64_encode( $public_key ),
];

// Drives a queued job to ready one bounded chunk per tick, bounded so a driver that
// never finishes fails the test rather than hangs. Returns the tick count.
$drive_to_ready = static function ( string $id, string $secret ) use ( $tick, $get_extraction ): int {
	$ticks = 0;
	while ( $ticks < 200 ) {
		$state = $get_extraction( $id )->get_data();
		if ( is_array( $state ) && ( $state['state'] ?? null ) === 'ready' ) {
			break;
		}
		$tick( $id, $secret );
		$ticks++;
	}
	return $ticks;
};

// Reads a ready job's published artifact off disk through its own download_url.
$artifact_bytes = static function ( array $poll ): string {
	$uploads = wp_upload_dir();
	$baseurl = rtrim( $uploads['baseurl'], '/' );
	$url = is_string( $poll['download_url'] ?? null ) ? $poll['download_url'] : '';
	$path = $url === '' ? '' : rtrim( $uploads['basedir'], '/' ) . substr( $url, strlen( $baseurl ) );
	return $path !== '' && is_file( $path ) ? (string) file_get_contents( $path ) : '';
};

// Concatenates, in index order, every segment sealed under one name — the same
// reassembly rule the file parts already use.
$reassemble = static function ( array $container, ?array $names, string $name, string $keypair ) use ( $open_segment ): string {
	$joined = '';
	foreach ( $container['records'] as $i => $record ) {
		if ( is_array( $names ) && ( $names[ $i ] ?? null ) === $name ) {
			$plain = $open_segment( $record, $keypair );
			$joined .= is_string( $plain ) ? $plain : '';
		}
	}
	return $joined;
};

// --- AC1/AC2/AC3: the three tables complete across many ticks and reassemble ---

wp_set_current_user( $owner->ID );
$response = $post_extractions( $selection );
kntnt_extractor_assert( $response->get_status() === 201, 'POST /extractions creates the multi-slice table job (201)' );
$created = $response->get_data();
$id = is_array( $created ) && is_string( $created['id'] ?? null ) ? $created['id'] : '';
$state = $read_state( $work, $id );
$secret = is_array( $state ) && is_string( $state['tick_secret'] ?? null ) ? $state['tick_secret'] : '';

$ticks_used = $drive_to_ready( $id, $secret );
$ready_poll = $get_extraction( $id )->get_data();
kntnt_extractor_assert( is_array( $ready_poll ) && ( $ready_poll['state'] ?? null ) === 'ready', 'A selection of three oversized tables reaches ready (AC1)' );

// Three tables would be three ticks plus a finalize if a table were packaged whole.
// Needing more than that is the observable proof each table spanned several ticks.
kntnt_extractor_assert( $ticks_used > 4, 'The tables complete across more ticks than there are tables, so each was chunked (AC1)' );

$raw = $artifact_bytes( is_array( $ready_poll ) ? $ready_poll : [] );
kntnt_extractor_assert( str_starts_with( $raw, Sealed_Writer::MAGIC ), 'The ready job publishes a sealed container (AC1)' );
$container = $parse( $raw );
$names = $open_index( $container['sealed_index'], $keypair );

// AC1: each table is recorded once per slice under its own name, exactly as an
// oversized file records one entry per part.
foreach ( [ $single_table, $composite_table, $keyless_table ] as $table ) {
	$slices = is_array( $names ) ? count( array_filter( $names, static fn( string $n ): bool => $n === $table ) ) : 0;
	kntnt_extractor_assert( $slices === $expected_slices, "The table {$table} is sealed as several segments under its own name (AC1)" );
}

// AC2: concatenating a table's segments in index order yields SQL byte-identical to
// the same table dumped in one slice. A chunk boundary is always a statement
// boundary, so chunking changes where the SQL is cut and nothing else.
foreach ( [ $single_table, $composite_table, $keyless_table ] as $table ) {
	$joined = $reassemble( $container, $names, $table, $keypair );
	kntnt_extractor_assert( $joined === $whole_dump( $table ), "The chunked dump of {$table} is byte-identical to the unchunked one (AC2)" );
}

// AC3: every row is carried exactly once, on all three key shapes. Keyset pagination
// that skipped or repeated a row across a slice boundary shows up here immediately.
$markers = [ $single_table => 'SINGLEMARK', $composite_table => 'COMPOSITEMARK', $keyless_table => 'KEYLESSMARK' ];
foreach ( $markers as $table => $marker ) {
	$joined = $reassemble( $container, $names, $table, $keypair );
	preg_match_all( '/' . $marker . '-\d+/', $joined, $found );
	kntnt_extractor_assert( count( $found[0] ) === $fixture_rows && count( array_unique( $found[0] ) ) === $fixture_rows, "Every row of {$table} is carried exactly once, with no skips or duplicates (AC3)" );
}

// AC3: the composite-key table comes back in its key's own (a, b) order, which is
// what proves both key columns are seeked on rather than only the first.
$composite_sql = $reassemble( $container, $names, $composite_table, $keypair );
preg_match_all( '/COMPOSITEMARK-(\d+)/', $composite_sql, $composite_found );
$emitted = array_map( 'intval', $composite_found[1] );
$expected_order = range( 1, $fixture_rows );
kntnt_extractor_assert( $emitted === $expected_order, 'The composite-key table is emitted in its full key order across every slice (AC3)' );

// --- AC4: interrupting mid-table and resuming keeps the committed prefix ---

wp_set_current_user( $owner->ID );
$r_response = $post_extractions( [ 'tables' => [ $single_table ], 'public_key' => base64_encode( $public_key ) ] );
$r_id = is_array( $r_response->get_data() ) ? (string) ( $r_response->get_data()['id'] ?? '' ) : '';
$r_secret = (string) ( ( $read_state( $work, $r_id ) ?? [] )['tick_secret'] ?? '' );

// Tick twice: two of the table's three slices, deliberately short of completion.
$tick( $r_id, $r_secret );
$tick( $r_id, $r_secret );
$partial = $read_state( $work, $r_id );
$progress = is_array( $partial ) && is_array( $partial['progress'] ?? null ) ? $partial['progress'] : [];

// The mid-table position is durably persisted: the rows already sealed and the key
// tuple the next slice seeks past, alongside the file offset that has always been
// there. Without both, the third slice could not resume where the second stopped.
kntnt_extractor_assert( is_array( $partial ) && ( $partial['state'] ?? null ) === 'running', 'A partially-dumped table leaves the job running between ticks (AC4)' );
kntnt_extractor_assert( ( $progress['tables_done'] ?? null ) === 0, 'The table is not counted done while it is still mid-dump (AC4)' );
kntnt_extractor_assert( ( $progress['table_offset'] ?? null ) === 2 * $slice_rows, 'The persisted progress carries the mid-table row offset (AC4)' );
kntnt_extractor_assert( ( $progress['table_cursor'] ?? null ) === [ (string) ( 2 * $slice_rows ) ], 'The persisted progress carries the keyset cursor the next slice seeks past (AC4)' );

// AC8: the poll can tell this healthy mid-table job from a wedged one. Its table
// counter has not moved and cannot until the whole table is sealed — which on a real
// site meant 3/186 standing still for minutes while the run was perfectly fine — so
// the chunk counter is what carries the liveness a stall rule needs.
$r_poll = $get_extraction( $r_id )->get_data();
$r_progress = is_array( $r_poll ) && is_array( $r_poll['progress'] ?? null ) ? $r_poll['progress'] : [];
kntnt_extractor_assert( ( $r_progress['tables_done'] ?? null ) === 0, 'A job two slices into its only table still reports zero tables done (AC8)' );
kntnt_extractor_assert( ( $r_progress['chunks_done'] ?? null ) === 2, 'The same poll reports two chunks done, so slow is distinguishable from stuck (AC8)' );

// Snapshot the committed prefix, then simulate a crashed partial write past it. A
// true resume appends after the prefix and never rewrites it; a rebuild would reseal
// the finished slices under fresh keys and nonces, changing those very bytes.
$build_file = '';
foreach ( glob( $work . '/' . $r_id . '/*' ) ?: [] as $candidate ) {
	if ( is_file( $candidate ) && str_ends_with( $candidate, '.building' ) ) {
		$build_file = $candidate;
	}
}
$committed_bytes = is_int( $progress['container_bytes'] ?? null ) ? $progress['container_bytes'] : 0;
$committed_prefix = $build_file !== '' && $committed_bytes > 0 ? substr( (string) file_get_contents( $build_file ), 0, $committed_bytes ) : '';
kntnt_extractor_assert( strlen( $committed_prefix ) === $committed_bytes && $committed_bytes > 0, 'The committed prefix of the in-progress container is captured before the interruption (AC4)' );
if ( $build_file !== '' ) {
	file_put_contents( $build_file, random_bytes( 32 ), FILE_APPEND );
}

$drive_to_ready( $r_id, $r_secret );
$r_ready = $get_extraction( $r_id )->get_data();
kntnt_extractor_assert( is_array( $r_ready ) && ( $r_ready['state'] ?? null ) === 'ready', 'A job interrupted mid-table resumes and reaches ready (AC4)' );

$r_raw = $artifact_bytes( is_array( $r_ready ) ? $r_ready : [] );
kntnt_extractor_assert( $committed_prefix !== '' && str_starts_with( $r_raw, $committed_prefix ), 'The resumed artifact keeps the exact committed prefix — completed slices are not redone or re-encrypted (AC4)' );

$r_container = $parse( $r_raw );
$r_names = $open_index( $r_container['sealed_index'], $keypair );
kntnt_extractor_assert( is_array( $r_names ) && count( $r_names ) === $expected_slices, 'The resumed container holds exactly one segment per slice — none redone, duplicated, or corrupted (AC4)' );
kntnt_extractor_assert( $reassemble( $r_container, $r_names, $single_table, $keypair ) === $whole_dump( $single_table ), 'The resumed dump is still byte-identical to the unchunked one (AC2/AC4)' );

// --- AC5: a chunk that never advances is failed rather than retried forever ---

// A real advance clears the attempt counter, which is what keeps a slow-but-advancing
// large build — one slice per cron cycle — out of the stall bound entirely.
wp_set_current_user( $owner->ID );
$s_response = $post_extractions( [ 'tables' => [ $single_table ], 'public_key' => base64_encode( $public_key ) ] );
$s_id = is_array( $s_response->get_data() ) ? (string) ( $s_response->get_data()['id'] ?? '' ) : '';
$s_secret = (string) ( ( $read_state( $work, $s_id ) ?? [] )['tick_secret'] ?? '' );
$tick( $s_id, $s_secret );
$s_state = $read_state( $work, $s_id ) ?? [];
kntnt_extractor_assert( ( $s_state['attempts'] ?? null ) === 0, 'A chunk that advances clears the no-progress attempt counter (AC5)' );

// One attempt short of the bound the job must still be driven, not failed: the bound
// exists to stop an impossible chunk, never to abandon a merely unlucky one.
$s_state['attempts'] = 1;
$write_state( $work, $s_id, $s_state );
$tick( $s_id, $s_secret );
$s_after = $read_state( $work, $s_id ) ?? [];
kntnt_extractor_assert( ( $s_after['state'] ?? null ) === 'running' && ( $s_after['attempts'] ?? null ) === 0, 'A job one attempt short of the bound still advances and resets the counter (AC5)' );

// At the bound, the attempts stand as evidence that the chunk was begun three times
// and finished none of them — the shape a memory or execution-time kill leaves. The
// job must now shrink the table-slice budget and keep going, not fail: failing here
// is what made every crash cost the whole run, and the bound exists to detect the
// wall, not to walk into it.
$s_after['attempts'] = 3;
$write_state( $work, $s_id, $s_after );
$tick( $s_id, $s_secret );
$s_adapted = $read_state( $work, $s_id ) ?? [];
kntnt_extractor_assert( ( $s_adapted['state'] ?? null ) !== 'failed', 'A stall whose table budget can still shrink does not fail the job (AC5)' );
kntnt_extractor_assert( ( $s_adapted['attempts'] ?? null ) === 0, 'Adapting a table stall resets the attempt counter (AC5)' );
kntnt_extractor_assert( is_int( $s_adapted['table_chunk_bytes'] ?? null ) && $s_adapted['table_chunk_bytes'] > 0 && $s_adapted['table_chunk_bytes'] < 4194304, 'A table-chunk stall halves the persisted byte budget (AC5)' );

// Both of the slice's bounds have to give. The byte budget caps only what is rendered,
// escaped and sealed; the rows themselves are materialised by `$wpdb->get_results()`
// under the row budget alone, which is the half a memory kill is likeliest to have
// happened in. Halving the bytes and leaving the fetch exactly as large as the one
// that just died would burn the next attempt window at the same real size — an
// adaptation that reads as one and measurably is not.
kntnt_extractor_assert( is_int( $s_adapted['table_chunk_rows'] ?? null ) && $s_adapted['table_chunk_rows'] > 0 && $s_adapted['table_chunk_rows'] < 1000, 'A table-chunk stall halves the persisted row budget too, so the fetch shrinks with the render (AC5)' );

// A byte budget alone at the floor is therefore NOT the floor: the fetch can still be
// halved, and that is a real attempt worth making rather than a failure. A fresh job
// carries it, because the adapted tick above may already have finished the table.
wp_set_current_user( $owner->ID );
$rows_response = $post_extractions( [ 'tables' => [ $single_table ], 'public_key' => base64_encode( $public_key ) ] );
$rows_id = is_array( $rows_response->get_data() ) ? (string) ( $rows_response->get_data()['id'] ?? '' ) : '';
$rows_secret = (string) ( ( $read_state( $work, $rows_id ) ?? [] )['tick_secret'] ?? '' );
$tick( $rows_id, $rows_secret );
$rows_state = $read_state( $work, $rows_id ) ?? [];
$rows_state['attempts'] = 3;
$rows_state['table_chunk_bytes'] = 1;
$rows_state['table_chunk_rows'] = 64;
$write_state( $work, $rows_id, $rows_state );
$tick( $rows_id, $rows_secret );
$s_rows_only = $read_state( $work, $rows_id ) ?? [];
kntnt_extractor_assert( ( $s_rows_only['state'] ?? null ) !== 'failed', 'A one-byte table budget whose row budget can still shrink does not fail the job (AC5)' );
kntnt_extractor_assert( ( $s_rows_only['table_chunk_rows'] ?? null ) === 32, 'That stall shrinks the row budget alone, the byte budget already being at its floor (AC5)' );

// The floor is what still fails the job: a slice whose byte AND row budgets have both
// been begun three times at one cannot shrink further, and retrying it would spin
// forever. A fresh job is used because the adapted ticks above may have finished the
// table.
wp_set_current_user( $owner->ID );
$floor_response = $post_extractions( [ 'tables' => [ $single_table ], 'public_key' => base64_encode( $public_key ) ] );
$floor_id = is_array( $floor_response->get_data() ) ? (string) ( $floor_response->get_data()['id'] ?? '' ) : '';
$floor_secret = (string) ( ( $read_state( $work, $floor_id ) ?? [] )['tick_secret'] ?? '' );
$tick( $floor_id, $floor_secret );
$floor_state = $read_state( $work, $floor_id ) ?? [];
$floor_row = is_array( $floor_state['progress'] ?? null ) && is_int( $floor_state['progress']['table_offset'] ?? null ) ? $floor_state['progress']['table_offset'] : 0;
$floor_state['attempts'] = 3;
$floor_state['table_chunk_bytes'] = 1;
$floor_state['table_chunk_rows'] = 1;
$write_state( $work, $floor_id, $floor_state );
$tick( $floor_id, $floor_secret );
$s_failed = $get_extraction( $floor_id )->get_data();
kntnt_extractor_assert( is_array( $s_failed ) && ( $s_failed['state'] ?? null ) === 'failed', 'A stall at a one-byte table budget fails the job instead of spinning (AC5)' );

// The reason has to explain the next occurrence with no server log to consult, so it
// names the chunk it stalled on and the two host limits that are almost always why.
$message = is_array( $s_failed ) && is_array( $s_failed['error'] ?? null ) ? (string) ( $s_failed['error']['message'] ?? '' ) : '';
kntnt_extractor_assert( $message !== '' && str_contains( $message, $single_table ), 'The failed poll names the table the build stalled on (AC5)' );
kntnt_extractor_assert( str_contains( $message, 'memory_limit' ) && str_contains( $message, 'max_execution_time' ), 'The failure reason names the two host limits that cause this stall (AC5)' );
kntnt_extractor_assert( str_contains( $message, 'row ' . $floor_row ), 'The failure reason names the row the advanced slices had reached, so the chunk is reproducible (AC5)' );

// --- AC5: the row budget the stall adaptation halves genuinely binds below one batch ---

// A budget under one INSERT batch bounds the fetch to itself, not to 100 rows. Before
// this fix every value from 1 to 100 rounded UP to a full batch and fetched exactly 100
// regardless, so the halving above stopped changing the real fetch long before it
// reached the floor ADR-0015 describes, and kept "adapting" without adapting anything.
[ , , $bound_rows_done ] = $dumper->dump_chunk( $single_table, null, 0, 10, PHP_INT_MAX );
kntnt_extractor_assert( $bound_rows_done === 10, 'AC5: a row budget below one INSERT batch bounds the fetch to itself, not to 100 (was: silently floored at 100)' );

// A budget that is not a multiple of one batch is never exceeded. The rounding goes
// DOWN, not up, so a slice never fetches more rows than the budget allows — the old
// rounding would have fetched 200 rows here, 50 over the 150-row budget.
[ , , $unrounded_rows_done ] = $dumper->dump_chunk( $single_table, null, 0, 150, PHP_INT_MAX );
kntnt_extractor_assert( $unrounded_rows_done <= 150, 'AC5: a row budget that is not a multiple of one INSERT batch is never exceeded (was: rounded up past itself)' );

// The floor is genuinely one row, not one hundred. A budget of 1 still renders exactly
// one row and advances the cursor rather than stalling or coming back empty — the two
// rules ("round down to the budget" and "always render at least one row") compose.
[ , $floor_cursor, $floor_rows_done, $floor_complete ] = $dumper->dump_chunk( $single_table, null, 0, 1, PHP_INT_MAX );
kntnt_extractor_assert( $floor_rows_done === 1, 'AC5: a row budget of one still renders exactly one row and advances the build (was: floored at 100)' );
kntnt_extractor_assert( $floor_cursor !== null && $floor_complete === false, 'AC5: a row budget of one advances the cursor rather than stalling or returning an empty slice' );

// --- AC6: a record written before this change is no longer readable ---

// This release understands the current schema and no earlier one (ADR-0024), so a
// record missing the schema-6 keys is not an older job to be resumed but no job at
// all. What has to be right is how it says so: quietly, without a throw, and
// without rewriting what it could not read.
wp_set_current_user( $owner->ID );
$l_response = $post_extractions( [ 'tables' => [ $single_table ], 'public_key' => base64_encode( $public_key ) ] );
$l_id = is_array( $l_response->get_data() ) ? (string) ( $l_response->get_data()['id'] ?? '' ) : '';
$l_secret = (string) ( ( $read_state( $work, $l_id ) ?? [] )['tick_secret'] ?? '' );
$tick( $l_id, $l_secret );

// Strip every schema-6 key to reproduce the on-disk shape the previous release wrote.
$legacy = $read_state( $work, $l_id ) ?? [];
unset( $legacy['attempts'], $legacy['error'] );
if ( is_array( $legacy['progress'] ?? null ) ) {
	unset( $legacy['progress']['table_offset'], $legacy['progress']['table_cursor'] );
}
$write_state( $work, $l_id, $legacy );

kntnt_extractor_assert( $get_extraction( $l_id )->get_status() === 404, 'A record missing the table position and attempt counter reads as no such job (AC6)' );

// A further tick has nothing to drive and must leave the record untouched, since
// nothing hunts down what an unsupported release wrote.
$tick( $l_id, $l_secret );
$l_after = $read_state( $work, $l_id ) ?? [];
kntnt_extractor_assert( ! array_key_exists( 'attempts', $l_after ) && ! array_key_exists( 'error', $l_after ), 'A further tick neither revives nor rewrites the unreadable record (AC6)' );

// --- AC7: a slice is bounded by bytes as well as by rows ---

// The shape a row budget alone cannot see, and the one that stopped a production
// clone: a table whose rows are fat enough that it sits far inside the row budget and
// still cannot be packaged in a single slice. On the real site it was 726 rows of
// ~23 KB, taken whole under a 1,000-row budget and never finishing; here it is 20 rows
// of ~50 KB under the 100-row budget forced above. The contrast that proves the unit
// was wrong is the fixtures above: 250 narrow rows split happily, this one would not.
$fat_table = 'kntnt_extractor_chunk_fat';
$fat_rows = 20;
$fat_width = 51200;
$wpdb->query( "DROP TABLE IF EXISTS `{$fat_table}`" );
$wpdb->query( "CREATE TABLE `{$fat_table}` ( `id` bigint(20) NOT NULL, `payload` longtext NOT NULL, PRIMARY KEY (`id`) )" );
for ( $i = 1; $i <= $fat_rows; $i++ ) {
	$wpdb->query( $wpdb->prepare( "INSERT INTO `{$fat_table}` (`id`, `payload`) VALUES (%d, %s)", $i, "FATMARK-{$i}-" . str_repeat( 'x', $fat_width ) ) );
}

// A byte budget a few of these rows fit inside but the table does not, while the
// 100-row budget this file forces is never approached by a 20-row table at all.
$fat_bytes = 4 * $fat_width;
$force_bytes = static fn(): int => $fat_bytes;
add_filter( 'kntnt_extractor_config_table_chunk_bytes', $force_bytes );

// The byte budget bounds the slice even though the row budget is nowhere near spent.
[ , , $fat_first_rows, $fat_first_complete ] = $dumper->dump_chunk( $fat_table, null, 0, 1000, $fat_bytes );
kntnt_extractor_assert( $fat_first_rows > 0 && $fat_first_rows < $fat_rows, 'A slice renders only the rows its byte budget holds, though its row budget is untouched (AC7)' );

// And a page cut short by that budget is NOT the end of the table. Deciding it on the
// short page alone — the rule that was sound while rows were the only bound — would
// end the table mid-row-set and seal a truncated dump that reloads without one error,
// which is strictly worse than the loud stall this replaces.
kntnt_extractor_assert( $fat_first_complete === false, 'A page cut short by the byte budget is not reported as the end of the table (AC7)' );

// A row wider than the entire budget must still be rendered: a slice that rendered
// nothing would advance no cursor and stall the build on a row it can never fit.
[ , , $fat_min_rows ] = $dumper->dump_chunk( $fat_table, null, 0, 1000, 1 );
kntnt_extractor_assert( $fat_min_rows === 1, 'A slice always renders at least one row, however small the byte budget (AC7)' );

// A page the budget did not cut, and that came back short, is still the end of the
// table — the byte bound must not turn every table into an endless one.
[ , , , $fat_whole_complete ] = $dumper->dump_chunk( $fat_table, null, 0, 1000, PHP_INT_MAX );
kntnt_extractor_assert( $fat_whole_complete === true, 'A short page the byte budget did not cut is still the end of the table (AC7)' );

// End to end: the table completes across several sealed slices, and every row is
// carried exactly once — the property a silently truncated table would break.
wp_set_current_user( $owner->ID );
$f_response = $post_extractions( [ 'tables' => [ $fat_table ], 'public_key' => base64_encode( $public_key ) ] );
$f_id = is_array( $f_response->get_data() ) ? (string) ( $f_response->get_data()['id'] ?? '' ) : '';
$f_secret = (string) ( ( $read_state( $work, $f_id ) ?? [] )['tick_secret'] ?? '' );
$drive_to_ready( $f_id, $f_secret );
$f_ready = $get_extraction( $f_id )->get_data();
kntnt_extractor_assert( is_array( $f_ready ) && ( $f_ready['state'] ?? null ) === 'ready', 'A table of few fat rows reaches ready instead of stalling on one oversized slice (AC7)' );

$f_container = $parse( $artifact_bytes( is_array( $f_ready ) ? $f_ready : [] ) );
$f_names = $open_index( $f_container['sealed_index'], $keypair );
$f_slices = is_array( $f_names ) ? count( array_filter( $f_names, static fn( string $n ): bool => $n === $fat_table ) ) : 0;
kntnt_extractor_assert( $f_slices > 1, 'A table under its row budget is still split by its byte budget (AC7)' );

$f_joined = $reassemble( $f_container, $f_names, $fat_table, $keypair );
preg_match_all( '/FATMARK-\d+/', $f_joined, $f_found );
kntnt_extractor_assert( count( $f_found[0] ) === $fat_rows && count( array_unique( $f_found[0] ) ) === $fat_rows, 'Every row of the byte-split table is carried exactly once, with no skips or duplicates (AC7)' );

// AC7: the byte cut must be correct on every key shape, not just the simple one.
// The fat fixture above has a single-column primary key, which is the easy case: the
// cursor is one value. A composite cursor is a tuple taken from the last RENDERED row,
// and a keyless table has no cursor at all and resumes on the rendered row count — so a
// byte cut that mis-set either would skip or duplicate rows across the slice boundary,
// and neither the dump nor the import would say a word about it. Drive each shape to
// completion under a budget small enough to cut inside a page and count the rows.
$drive_dump = static function ( string $table, int $max_bytes ) use ( $dumper ): array {
	$sql = '';
	$rows_done = 0;
	$cursor = null;
	$slices = 0;
	do {
		[ $part, $cursor, $rows_done, $complete ] = $dumper->dump_chunk( $table, $cursor, $rows_done, 1000, $max_bytes );
		$sql .= $part;
		$slices++;
	} while ( ! $complete && $slices < 500 );
	return [ $sql, $rows_done, $slices ];
};

foreach ( [ $composite_table => 'COMPOSITEMARK', $keyless_table => 'KEYLESSMARK', $single_table => 'SINGLEMARK' ] as $shape_table => $shape_marker ) {
	[ $shape_sql, $shape_rows, $shape_slices ] = $drive_dump( $shape_table, 300 );
	preg_match_all( '/' . $shape_marker . '-(\d+)/', $shape_sql, $shape_found );

	// The budget must actually have bitten, or the assertions below prove nothing.
	kntnt_extractor_assert( $shape_slices > 1, "The byte budget splits {$shape_table} into several slices (AC7)" );
	kntnt_extractor_assert( $shape_rows === $fixture_rows, "Byte-cut slicing of {$shape_table} renders every row exactly once by count (AC7)" );
	kntnt_extractor_assert( count( $shape_found[0] ) === $fixture_rows && count( array_unique( $shape_found[0] ) ) === $fixture_rows, "Byte-cut slicing of {$shape_table} skips and duplicates no row (AC7)" );
	kntnt_extractor_assert( array_map( 'intval', $shape_found[1] ) === range( 1, $fixture_rows ), "Byte-cut slicing of {$shape_table} keeps the rows in order across every cut (AC7)" );
}

remove_filter( 'kntnt_extractor_config_table_chunk_bytes', $force_bytes );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_table_chunk_rows', $force_slice );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
$wpdb->query( "DROP TABLE IF EXISTS `{$single_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$composite_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$keyless_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$fat_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$late_table}`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$key_table}`" );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );
