<?php
/**
 * Integration test: the Manifest walks a tree in canonical path order and pages
 * through an opaque cursor with no gaps or duplicates.
 *
 * The Manifest is the file-side discovery primitive (ADR-0003): every regular
 * file from a root downward, reported as path/size/mtime with no categorisation.
 * This harness exercises the Manifest directly against a controlled temporary
 * tree — the layer that actually owns cursor correctness — rather than the whole
 * WordPress install, so the ordering and reassembly guarantees can be asserted
 * against a known-good reference. The `/files` REST wiring is exercised
 * separately in files-endpoint-test.php.
 *
 * The fixture tree is deliberately adversarial: a directory (`foo`) and a sibling
 * file (`foo.txt`) whose names collide on a prefix. A naive full-string sort would
 * order `foo.txt` before `foo/…` (because `.` is byte 0x2E and `/` is 0x2F), but
 * the canonical depth-first, per-component order places every file under `foo/`
 * first. Paging with page sizes of 1, 2, 3 and 5 must reassemble exactly the same
 * ordered listing, which is the strongest available proof that the cursor resumes
 * without a gap or a duplicate.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

// The class under test must exist before anything else can be asserted; a missing
// class is itself a failing assertion rather than a fatal that aborts the suite.
if ( ! class_exists( \Kntnt\Extractor\Manifest::class ) ) {
	kntnt_extractor_assert( false, 'The Manifest class is available' );
	return;
}

/**
 * Recursively removes a directory tree created by this test.
 *
 * @param string $path Absolute path to remove.
 * @return void
 */
$kntnt_extractor_rmtree = static function ( string $path ) use ( &$kntnt_extractor_rmtree ): void {
	foreach ( array_diff( scandir( $path ) ?: [], [ '.', '..' ] ) as $entry ) {
		$child = $path . '/' . $entry;
		is_dir( $child ) && ! is_link( $child ) ? $kntnt_extractor_rmtree( $child ) : unlink( $child );
	}
	rmdir( $path );
};

// Build the adversarial fixture tree under a unique temporary root.
$root = sys_get_temp_dir() . '/kntnt-extractor-manifest-' . uniqid();
foreach ( [ 'a', 'foo', 'foo/inner', 'empty' ] as $dir ) {
	mkdir( $root . '/' . $dir, 0777, true );
}
$files = [
	'.hidden' => 'h',
	'a/a1.txt' => 'a1',
	'a/a2.txt' => 'a2',
	'b.txt' => 'hello',
	'foo/bar.txt' => 'bar',
	'foo/inner/deep.txt' => 'deep',
	'foo.txt' => 'foofile',
	'z.txt' => 'z',
];
foreach ( $files as $rel => $content ) {
	file_put_contents( $root . '/' . $rel, $content );
}

// The canonical order is depth-first, siblings sorted by byte value, files only.
// Note that everything under `foo/` precedes `foo.txt`, which a full-string sort
// would get wrong — this list encodes the component-wise ordering contract.
$expected = [
	'.hidden',
	'a/a1.txt',
	'a/a2.txt',
	'b.txt',
	'foo/bar.txt',
	'foo/inner/deep.txt',
	'foo.txt',
	'z.txt',
];

// A single large page returns the whole listing, in canonical order, exhausted.
$manifest = new \Kntnt\Extractor\Manifest( $root, 100 );
$page = $manifest->page( null );
$paths = array_map( static fn( array $f ): string => $f['path'], $page['files'] );
kntnt_extractor_assert( $paths === $expected, 'A single page lists every file in canonical depth-first, per-component order' );
kntnt_extractor_assert( $page['cursor'] === null, 'The final page carries a null cursor (listing exhausted)' );

// Directories are never emitted — only the leaf files are.
$dir_leaked = array_intersect( $paths, [ 'a', 'foo', 'foo/inner', 'empty' ] );
kntnt_extractor_assert( $dir_leaked === [], 'No directory is reported as a Manifest entry' );

// Every entry carries exactly path, size and mtime — no categorisation field.
$well_formed = true;
$reconstructable = true;
foreach ( $page['files'] as $entry ) {
	$keys = array_keys( $entry );
	sort( $keys );
	if ( $keys !== [ 'mtime', 'path', 'size' ]
		|| ! is_string( $entry['path'] )
		|| ! is_int( $entry['size'] )
		|| ! is_int( $entry['mtime'] )
		|| $entry['size'] < 0
		|| $entry['mtime'] <= 0
		|| str_starts_with( $entry['path'], '/' ) ) {
		$well_formed = false;
	}
	if ( ! is_file( $root . '/' . $entry['path'] ) ) {
		$reconstructable = false;
	}
}
kntnt_extractor_assert( $well_formed, 'Each entry carries exactly path, size and mtime, path relative to the root' );
kntnt_extractor_assert( $reconstructable, 'Each reported path resolves to a real file under the root' );

// The reported size is the file's real byte length, not a placeholder.
$by_path = [];
foreach ( $page['files'] as $entry ) {
	$by_path[ $entry['path'] ] = $entry;
}
kntnt_extractor_assert( $by_path['b.txt']['size'] === 5, 'The reported size is the file\'s real byte length' );

/**
 * Pages the Manifest to exhaustion at a given page size and returns the ordered
 * paths, asserting no page ever exceeds the page size.
 *
 * @param string $root      Root the Manifest is rooted at.
 * @param int    $page_size Entries per page.
 * @return list<string> The reassembled ordered paths.
 */
$reassemble = static function ( string $root, int $page_size ): array {
	$manifest = new \Kntnt\Extractor\Manifest( $root, $page_size );
	$paths = [];
	$cursor = null;
	$guard = 0;
	do {
		$page = $manifest->page( $cursor );
		kntnt_extractor_assert( count( $page['files'] ) <= $page_size, "A page never exceeds the page size ({$page_size})" );
		foreach ( $page['files'] as $entry ) {
			$paths[] = $entry['path'];
		}
		$cursor = $page['cursor'];
		++$guard;
	} while ( $cursor !== null && $guard < 1000 );
	return $paths;
};

// Following the cursor to exhaustion reassembles the whole listing — same order,
// no gaps, no duplicates — at every page size, including the brutal size of 1.
foreach ( [ 1, 2, 3, 5, 100 ] as $page_size ) {
	$got = $reassemble( $root, $page_size );
	kntnt_extractor_assert( $got === $expected, "Paging at size {$page_size} reassembles the whole listing in order" );
	kntnt_extractor_assert( count( $got ) === count( array_unique( $got ) ), "Paging at size {$page_size} produces no duplicates" );
}

// The cursor is opaque: an encoded token, not the bare path it resumes after.
$first = ( new \Kntnt\Extractor\Manifest( $root, 1 ) )->page( null );
kntnt_extractor_assert( is_string( $first['cursor'] ) && $first['cursor'] !== '', 'A non-final page carries a non-empty cursor' );
kntnt_extractor_assert( is_string( $first['cursor'] ) && preg_match( '/^[A-Za-z0-9_-]+$/', $first['cursor'] ) === 1, 'The cursor is an opaque URL-safe token' );
kntnt_extractor_assert( $first['cursor'] !== $expected[0], 'The cursor is not the raw resume-after path' );

// A malformed cursor is rejected rather than silently restarting the listing.
$rejects = static function ( string $cursor ) use ( $root ): bool {
	try {
		( new \Kntnt\Extractor\Manifest( $root, 2 ) )->page( $cursor );
		return false;
	} catch ( \InvalidArgumentException ) {
		return true;
	}
};
kntnt_extractor_assert( $rejects( '@@@not-base64@@@' ), 'A cursor outside the token alphabet is rejected' );
$parent_cursor = rtrim( strtr( base64_encode( '../etc/passwd' ), '+/', '-_' ), '=' );
kntnt_extractor_assert( $rejects( $parent_cursor ), 'A cursor with parent-directory components is rejected' );

// Leave the machine state-neutral: remove the fixture tree.
$kntnt_extractor_rmtree( $root );
