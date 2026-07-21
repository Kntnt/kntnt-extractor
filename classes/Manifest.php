<?php
/**
 * The recursive file Manifest with opaque, path-ordered cursor pagination.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use Generator;
use InvalidArgumentException;

/**
 * Produces the recursive file Manifest (path, size, mtime) rooted at a single
 * directory, one bounded page at a time.
 *
 * The Manifest is the plugin's file-side discovery primitive (ADR-0003): every
 * regular file from the given root downward, reported exactly as it exists with
 * no categorisation of what any file is for. A large installation is never
 * materialised in one response — the listing is delivered complete but paged
 * through an opaque, path-ordered cursor the caller loops over to exhaustion.
 * Each page holds at most `page_size` entries, and the walk keeps only one
 * directory's sorted names and the recursion stack live at a time, so neither the
 * collected page nor the traversal state grows with the total file count.
 *
 * The canonical order is a depth-first pre-order traversal whose siblings are
 * sorted by byte value at every directory level. Equivalently, two file paths are
 * ordered by comparing their `/`-separated components pairwise, the first
 * differing component deciding. Because only leaf files are emitted, no emitted
 * path is an ancestor of another, so this is a total order: a cursor naming the
 * last emitted path unambiguously resumes "every file after it". A file added or
 * removed between pages shifts only itself — the resume point is structural, not
 * an offset, so the paging never double-counts or skips a stable file.
 *
 * @since 0.1.0
 */
final class Manifest {

	/**
	 * Absolute filesystem root the Manifest is rooted at, without a trailing slash.
	 *
	 * Reported paths are relative to this directory, `/`-separated and without a
	 * leading slash, so the Manifest never discloses the server's absolute layout.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $root;

	/**
	 * Builds a Manifest rooted at a directory with a fixed page size.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root      Absolute path to the directory to walk downward from.
	 * @param int    $page_size Maximum entries per page; the caller guarantees >= 1.
	 */
	public function __construct( string $root, private readonly int $page_size ) {

		// Normalise away a trailing slash once so every relative path derives from a
		// single stable prefix, whatever form the caller passed the root in.
		$this->root = rtrim( $root, '/' );

	}

	/**
	 * Returns one page of the Manifest, resuming after an opaque cursor.
	 *
	 * The page holds up to `page_size` entries in canonical order. When more files
	 * remain, `cursor` is a non-null opaque token to pass back for the next page;
	 * when the listing is exhausted, `cursor` is null. The one-past-the-page peek
	 * means the final page never trails an empty page.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $cursor Opaque token from a previous page, or null to
	 *                            start from the beginning.
	 * @return array{files: list<array{path: string, size: int, mtime: int}>, cursor: string|null}
	 *
	 * @throws InvalidArgumentException When a non-null cursor is malformed — a
	 *                                  client error, never a silent restart.
	 */
	public function page( ?string $cursor ): array {

		// Decode the cursor into the path components to resume after, or null on the
		// first page. Decoding validates the token at this untrusted boundary.
		$after = $cursor === null ? null : $this->decode_cursor( $cursor );

		// Collect up to page_size entries, then let the generator advance once more:
		// if it still yields, more pages remain and the cursor points at the last
		// emitted path; otherwise the listing is exhausted and the cursor is null.
		$files = [];
		$last_path = '';
		$has_more = false;
		foreach ( $this->walk( $this->root, '', $after ) as $entry ) {
			if ( count( $files ) === $this->page_size ) {
				$has_more = true;
				break;
			}
			$files[] = $entry;
			$last_path = $entry['path'];
		}

		return [
			'files' => $files,
			'cursor' => $has_more ? $this->encode_cursor( $last_path ) : null,
		];

	}

	/**
	 * Yields every regular file at or below a directory, in canonical order.
	 *
	 * Recurses depth-first with siblings sorted by byte value, seeking past the
	 * cursor as it descends: `$after` holds the cursor's remaining components for
	 * the subtree being entered, or null once the traversal is entirely past the
	 * cursor and everything below is emitted.
	 *
	 * @since 0.1.0
	 *
	 * @param string            $abs_dir Absolute path of the directory to traverse.
	 * @param string            $rel     Its path relative to the root, '' at the root.
	 * @param list<string>|null $after   Cursor components still to seek past here,
	 *                                   or null to emit the whole subtree.
	 * @return Generator<int, array{path: string, size: int, mtime: int}, mixed, void>
	 */
	private function walk( string $abs_dir, string $rel, ?array $after ): Generator {

		// Read the directory's own names and order them by byte value — the single
		// level of the canonical depth-first order. Sorting explicitly with
		// SORT_STRING keeps the traversal identical to the strcmp seek below, so a
		// numeric-looking name (`10` vs `9`) cannot reorder the two against each other.
		$entries = scandir( $abs_dir, SCANDIR_SORT_NONE );
		$names = array_diff( $entries === false ? [] : $entries, [ '.', '..' ] );
		sort( $names, SORT_STRING );

		foreach ( $names as $name ) {

			// Skip an entry whose name sorts before the cursor's component at this
			// depth: its whole subtree, or the file itself, lies before the resume point.
			if ( $after !== null && strcmp( $name, $after[0] ) < 0 ) {
				continue;
			}

			$abs = $abs_dir . '/' . $name;
			$child_rel = $rel === '' ? $name : $rel . '/' . $name;

			// Descend into a real subdirectory, carrying the cursor's remaining tail
			// down only along its own component and emitting the whole subtree once
			// past it. A symlinked directory is never followed — that bounds the walk
			// against cycles and keeps it inside the root (ADR-0003).
			if ( is_dir( $abs ) && ! is_link( $abs ) ) {
				$tail = $after !== null && $name === $after[0] ? array_slice( $after, 1 ) : [];
				yield from $this->walk( $abs, $child_rel, $tail === [] ? null : $tail );
				continue;
			}

			// Emit a regular file, unless its name equals the cursor's component here:
			// that is either the cursor path itself or an ancestor-name collision, and
			// both sort at or before the resume point.
			if ( is_file( $abs ) ) {
				if ( $after !== null && $name === $after[0] ) {
					continue;
				}
				yield [
					'path' => $child_rel,
					'size' => $this->size_of( $abs ),
					'mtime' => $this->mtime_of( $abs ),
				];
			}

		}

	}

	/**
	 * Returns a file's byte size, or 0 when the size cannot be read.
	 *
	 * @since 0.1.0
	 *
	 * @param string $abs Absolute path to the file.
	 * @return int The file size in bytes.
	 */
	private function size_of( string $abs ): int {

		$size = filesize( $abs );
		return $size === false ? 0 : $size;

	}

	/**
	 * Returns a file's modification time, or 0 when it cannot be read.
	 *
	 * @since 0.1.0
	 *
	 * @param string $abs Absolute path to the file.
	 * @return int The modification time as a Unix timestamp.
	 */
	private function mtime_of( string $abs ): int {

		$mtime = filemtime( $abs );
		return $mtime === false ? 0 : $mtime;

	}

	/**
	 * Encodes a resume-after path into an opaque, URL-safe cursor token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The last emitted path, relative to the root.
	 * @return string The opaque cursor.
	 */
	private function encode_cursor( string $path ): string {

		return rtrim( strtr( base64_encode( $path ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- benign: encodes an opaque pagination cursor, not code.

	}

	/**
	 * Decodes an opaque cursor back into the path components to resume after.
	 *
	 * A cursor may only name a path the walk could itself have emitted: relative,
	 * `/`-separated, with no empty, current, or parent component. Anything else is
	 * rejected outright rather than resumed from a nonsense position.
	 *
	 * @since 0.1.0
	 *
	 * @param string $cursor The opaque token supplied by the caller.
	 * @return list<string> The resume-after path split into its components.
	 *
	 * @throws InvalidArgumentException When the token is not a well-formed cursor.
	 */
	private function decode_cursor( string $cursor ): array {

		// Reverse the URL-safe base64; strict decoding rejects any byte outside the
		// alphabet, so a tampered or truncated token fails fast.
		$decoded = base64_decode( strtr( $cursor, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- benign: decodes an opaque pagination cursor, not code.
		if ( $decoded === false || $decoded === '' || str_contains( $decoded, "\0" ) ) {
			throw new InvalidArgumentException( 'Malformed pagination cursor.' );
		}

		// Accept only the shape the walk emits: no leading slash and no empty, `.` or
		// `..` component. This keeps a cursor a pure resume position, never a lever.
		$components = explode( '/', $decoded );
		foreach ( $components as $component ) {
			if ( $component === '' || $component === '.' || $component === '..' ) {
				throw new InvalidArgumentException( 'Malformed pagination cursor.' );
			}
		}

		return $components;

	}

}
