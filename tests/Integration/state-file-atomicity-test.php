<?php
/**
 * Integration test: the job state file is published atomically (issue #20).
 *
 * `GET /extractions/{id}` returned a spurious `404 kntnt_extractor_no_such_job`
 * twice while the job was demonstrably alive and progressing. The cause was a
 * non-atomic in-place rewrite of `job.json`: `Job_Store::write_file()` truncated
 * and rewrote the live state file on every save, so a poll that read it inside that
 * window saw a zero-length or partial file and `find()` folded that into "no such
 * job". The write pressure from the ADR-0010 time-budgeted tick (issue #18) made
 * the window easy to hit. A spurious 404 is worse than a hang, because the client
 * poll discipline treats a vanished job as terminal and aborts a healthy clone.
 *
 * This file pins the fix's observable contract:
 *  - AC (atomic write): a save replaces `job.json` by writing a sibling temp file
 *    and renaming it over the target — never an in-place O_TRUNC rewrite. A reader
 *    that opened the file before the save keeps reading the whole pre-save record
 *    through its handle (the rename swaps the file out from under it, leaving the
 *    previous bytes intact on the now-unlinked inode); an in-place rewrite would
 *    instead expose the new — and momentarily partial — bytes through that same
 *    handle. This is the property that guarantees a concurrent poll never observes a
 *    torn or truncated `job.json`.
 *  - AC (write leaves the file whole): after a save the state file parses to the
 *    saved record, and no `.tmp` residue is left behind in the job's directory.
 *  - AC (verified absence): `find()` returns null only for a job that is genuinely
 *    absent on disk. A present-but-empty state file is reported as null (after the
 *    bounded re-read) rather than raised or looped on — genuine corruption still
 *    reads as no such job, but the write path above never produces it.
 *  - AC (a partial sibling never masquerades as the job): a truncated temp sibling
 *    beside a complete `job.json` never makes `find()`/`all()` miss the live job.
 *
 * The true race is a multi-process condition (two PHP requests interleaving a write
 * and a read) that a single-threaded harness cannot reproduce; per the coding
 * standard that belongs in the DDEV suite. That includes the bounded re-read's own
 * recovery path — a read that fails then succeeds on a retry needs a concurrent
 * writer to land the rename between the two reads, which a synchronous loop has no
 * yield point for. What is deterministically testable here is the atomic-write
 * discipline that closes the window, which is what these assertions lock in.
 *
 * @package Kntnt\Extractor
 * @since   0.2.2
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Config;
use Kntnt\Extractor\Extraction_Job;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Job_Store;

// Recursively removes a directory tree so the run leaves no working directory on
// the host.
$sfa_rmrf = static function ( string $dir ) use ( &$sfa_rmrf ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: [] as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			$sfa_rmrf( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
};

// Redirect the store to an isolated tree still under uploads, so the run owns all
// of its state and cleans it up afterwards.
$sfa_work = wp_upload_dir()['basedir'] . '/kntnt-extractor-atomicity-' . bin2hex( random_bytes( 4 ) );
$sfa_force_work = static fn(): string => $sfa_work;
add_filter( 'kntnt_extractor_config_work_dir', $sfa_force_work );

$sfa_store = new Job_Store( new Config() );

// The caller submits only the public half of an ephemeral X25519 keypair.
$sfa_public_key = base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) );

// --- AC: a save replaces job.json atomically, never rewriting it in place --------

// Create a job and confirm its freshly-written state file is whole and parseable.
$sfa_job = $sfa_store->create( 1, $sfa_public_key, [], [], [ 'wp-load.php' ] );
$sfa_state = $sfa_work . '/' . $sfa_job->id . '/job.json';
kntnt_extractor_assert(
	is_array( json_decode( (string) file_get_contents( $sfa_state ), true ) ),
	'A freshly created job writes a whole, parseable job.json',
);

// Open a read handle on the state file, then save a transition over it. An atomic
// temp+rename swaps the file out from under the open handle, which keeps reading the
// whole previous record off the now-unlinked inode; an in-place O_TRUNC rewrite — the
// race that produced the spurious 404s — would instead expose the new (momentarily
// partial) bytes through that same handle.
$sfa_handle = fopen( $sfa_state, 'r' );
$sfa_store->save( $sfa_job->with_state( Job_State::Running ) );
$sfa_snapshot = json_decode( (string) stream_get_contents( $sfa_handle ), true );
fclose( $sfa_handle );
kntnt_extractor_assert(
	is_array( $sfa_snapshot ) && ( $sfa_snapshot['state'] ?? null ) === 'queued',
	'A read handle opened before a save keeps reading the whole pre-save record — the save publishes atomically (temp + rename), never tearing the live file in place',
);

// A fresh read after the save sees the whole published transition, so the save did
// take effect — it was published atomically, not skipped.
$sfa_after = json_decode( (string) file_get_contents( $sfa_state ), true );
kntnt_extractor_assert(
	is_array( $sfa_after ) && ( $sfa_after['state'] ?? null ) === 'running',
	'The atomically published job.json is whole and holds the saved state',
);

// --- AC: the write leaves no temp residue in the job's directory ----------------

// Only the job's own state file and hardening index.html remain — the temp sibling
// the atomic write used was renamed away, never left behind.
$sfa_entries = array_values( array_diff( scandir( $sfa_work . '/' . $sfa_job->id ) ?: [], [ '.', '..' ] ) );
sort( $sfa_entries );
kntnt_extractor_assert(
	$sfa_entries === [ 'index.html', 'job.json' ],
	'An atomic save leaves no .tmp residue in the job directory',
);

// --- AC: find() returns null only for a genuinely absent job --------------------

// A job that never existed is a verified absence.
kntnt_extractor_assert(
	$sfa_store->find( str_repeat( 'a', 32 ) ) === null,
	'find() returns null for a job that is genuinely absent on disk',
);

// A present-but-empty state file is reported as null after the bounded re-read —
// genuine corruption still reads as no such job, without raising or looping.
$sfa_job2 = $sfa_store->create( 1, $sfa_public_key, [], [], [ 'wp-load.php' ] );
$sfa_state2 = $sfa_work . '/' . $sfa_job2->id . '/job.json';
file_put_contents( $sfa_state2, '' );
clearstatcache();
kntnt_extractor_assert(
	$sfa_store->find( $sfa_job2->id ) === null,
	'find() reports a present-but-empty state file as null, without raising or looping',
);

// --- AC: a truncated temp sibling never masquerades as the live job -------------

// A live job with a complete job.json and a half-written temp sibling beside it —
// exactly the on-disk shape mid-atomic-write — still resolves to the live job, and
// the temp sibling is never mistaken for a second job by the directory walk.
$sfa_job3 = $sfa_store->create( 1, $sfa_public_key, [], [], [ 'wp-load.php' ] );
file_put_contents( $sfa_work . '/' . $sfa_job3->id . '/job.json.deadbeef.tmp', '{"partial":' );
clearstatcache();
$sfa_found = $sfa_store->find( $sfa_job3->id );
kntnt_extractor_assert(
	$sfa_found instanceof Extraction_Job && $sfa_found->id === $sfa_job3->id,
	'A truncated temp sibling beside a complete job.json never makes find() miss the live job',
);
$sfa_ids = array_map( static fn( Extraction_Job $j ): string => $j->id, $sfa_store->all() );
kntnt_extractor_assert(
	count( array_filter( $sfa_ids, static fn( string $id ): bool => $id === $sfa_job3->id ) ) === 1,
	'The directory walk counts the live job exactly once, never doubling it for the temp sibling',
);

// Leave the suite state clean for later files.
remove_filter( 'kntnt_extractor_config_work_dir', $sfa_force_work );
$sfa_rmrf( $sfa_work );
$sfa_rmrf( $sfa_work . '-downloads' );
