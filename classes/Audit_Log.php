<?php
/**
 * The audit log: a non-evadable, file-based record of every completed extraction.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use Kntnt\Extractor\Rest\Status_Controller;
use RuntimeException;
use Throwable;
use WP_User;

/**
 * Records every completed extraction and reads the record back (ADR-0006).
 *
 * A record is written the moment a job reaches ready — the sanctioned trigger the
 * {@see Dispatcher} fires as `kntnt_extractor_job_ready` — and never at consume, so
 * it cannot be evaded by fetching an artifact without confirming (ADR-0004). Each
 * record is one JSON Lines entry appended under an exclusive lock to a single
 * append-only file.
 *
 * The file is a dedicated, randomly-named `.jsonl` under a folder in the uploads
 * directory — the one location WordPress guarantees is writable. Its name is a long
 * random string because the uploads directory is ordinarily web-reachable and there
 * is no server-config lockdown that works identically on Apache and nginx; the
 * unguessable name, not an `.htaccess`, is the defence against direct-URL discovery,
 * and the endpoint below is the only sanctioned read path (ADR-0006). The folder
 * carries an index.html to silence directory listing, but deliberately no deny rule,
 * matching that ADR's reasoning.
 *
 * Rotation is by age, not entry count: an entry older than the retention window is
 * dropped whenever the log is written or read. When rotation empties the log the file
 * (and its directory, if then empty) is deleted and the recorded path forgotten, so
 * the next event mints a fresh randomly-named file rather than reusing a leaked name.
 *
 * @since 0.1.0
 */
final class Audit_Log {

	/**
	 * Option key holding the absolute path to the current randomly-named log file.
	 *
	 * The random name is not derivable, so the plugin remembers where the log is
	 * through this option rather than by scanning; clearing it is how an emptied log
	 * forgets its old name so the next event mints a fresh one.
	 *
	 * @since 0.1.0
	 */
	public const string OPTION = 'kntnt_extractor_audit_log';

	/**
	 * Name of the dedicated audit folder under the uploads directory.
	 *
	 * @since 0.1.0
	 */
	private const string DIR_NAME = 'kntnt-extractor-audit';

	/**
	 * Default retention window in days when neither the constant nor the filter sets one.
	 *
	 * @since 0.1.0
	 */
	private const int DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Appends one audit entry for a job that has just reached ready.
	 *
	 * Hooked on `kntnt_extractor_job_ready`, so it records exactly when a job becomes
	 * downloadable and never at consume (ADR-0004/0006). The entry binds to the job's
	 * own owner rather than the current user, because the ready transition is driven by
	 * a secret-authenticated loopback tick that carries no WordPress session.
	 *
	 * Recording is best-effort by construction: a failure here — a full disk at the
	 * instant a job goes ready — must never throw back into the extraction that just
	 * succeeded, so every fault is swallowed rather than surfaced. The write itself is
	 * atomic and lock-guarded, so a concurrent record cannot interleave a half-line.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job that has reached ready.
	 * @return void
	 */
	public function record( Extraction_Job $job ): void {

		try {

			// Prune anything already past the retention window before adding today's
			// entry, so the log never grows unbounded between reads.
			$this->rotate();

			// Build the entry from the job's own record — the owner, the full-data table
			// list, the structure-only table list (issue #16, kept distinct so the record
			// says which tables shipped without their rows), and a privacy-conscious files
			// digest (the full paths are only hashed, never stored) — and stamp the API
			// version the contract reports.
			$entry = [
				'ts' => time(),
				'user_id' => $job->owner,
				'user_login' => $this->login_for( $job->owner ),
				'api_version' => Status_Controller::API_VERSION,
				'job_id' => $job->id,
				'tables' => array_values( $job->tables ),
				'tables_structure_only' => array_values( $job->structure_only ),
				'files' => $this->files_summary( $job->files ),
			];
			$line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( $line === false ) {
				return;
			}
			$this->append( $line );

		} catch ( Throwable ) {

			// Swallow: an audit failure at the ready instant must not break the
			// extraction. The record is written at ready or, on a genuine filesystem
			// fault, not at all — never at the cost of the job itself.
			return;

		}

	}

	/**
	 * Returns the recorded entries, newest first, filtered and paginated.
	 *
	 * Rotates first, so a read never returns an entry the retention window has already
	 * expired. The optional `from`/`to` bounds are inclusive whole days in UTC, and
	 * paging slices the newest-first list into fixed-size pages.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $from     Inclusive lower date bound (`Y-m-d`), or null for no lower bound.
	 * @param string|null $to       Inclusive upper date bound (`Y-m-d`), or null for no upper bound.
	 * @param int         $page     1-based page number.
	 * @param int         $per_page Entries per page.
	 * @return array{entries: list<array<array-key, mixed>>, total: int, page: int, per_page: int}
	 */
	public function entries( ?string $from, ?string $to, int $page, int $per_page ): array {

		// Rotate before reading so an expired entry is never returned, then load the
		// current log (an absent one simply reads as no entries).
		$this->rotate();
		$path = $this->current_path();
		$all = ( $path !== null && is_file( $path ) ) ? $this->read_all( $path ) : [];

		// Keep only entries inside the inclusive UTC-day window, if one was given.
		$from_ts = $this->day_start( $from );
		$to_ts = $this->day_end( $to );
		$filtered = array_values(
			array_filter(
				$all,
				static function ( array $entry ) use ( $from_ts, $to_ts ): bool {
					$ts = $entry['ts'] ?? null;
					if ( ! is_int( $ts ) ) {
						return false;
					}
					if ( $from_ts !== null && $ts < $from_ts ) {
						return false;
					}
					return ! ( $to_ts !== null && $ts > $to_ts );
				},
			),
		);

		// Newest first, then slice out the requested page.
		usort( $filtered, static fn( array $a, array $b ): int => ( is_int( $b['ts'] ?? null ) ? $b['ts'] : 0 ) <=> ( is_int( $a['ts'] ?? null ) ? $a['ts'] : 0 ) );
		$page = max( 1, $page );
		$per_page = max( 1, $per_page );
		$page_entries = array_values( array_slice( $filtered, ( $page - 1 ) * $per_page, $per_page ) );

		// Publish ts in the contract's ISO-8601 UTC form. The record keeps ts as an
		// integer Unix timestamp on disk, and the rotation cutoff and the newest-first
		// ordering above both read it as one; only this outward-facing representation is
		// the string the contract documents, so the conversion happens last, on the page
		// actually returned, and never touches the on-disk log or the math above.
		$page_entries = array_map(
			static function ( array $entry ): array {
				if ( isset( $entry['ts'] ) && is_int( $entry['ts'] ) ) {
					$entry['ts'] = gmdate( 'Y-m-d\TH:i:s\Z', $entry['ts'] );
				}
				return $entry;
			},
			$page_entries,
		);

		return [
			'entries' => $page_entries,
			'total' => count( $filtered ),
			'page' => $page,
			'per_page' => $per_page,
		];

	}

	/**
	 * Drops every entry older than the retention window, deleting an emptied log.
	 *
	 * Runs under an exclusive lock so a concurrent append cannot race the rewrite. When
	 * pruning removes the last entry, the file and — if it is then empty — its directory
	 * are deleted and the recorded path forgotten, so a leaked file name is worthless
	 * afterwards and the next event mints a fresh one (ADR-0006).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function rotate(): void {

		// Nothing to rotate until a log exists.
		$path = $this->current_path();
		if ( $path === null || ! is_file( $path ) ) {
			return;
		}

		// Open for read-and-write without truncating, and take an exclusive lock so the
		// prune-and-rewrite is atomic against a concurrent append.
		$handle = fopen( $path, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- locked read-modify-write of the plugin's own append-only log; WP_Filesystem has no locking API.
		if ( $handle === false ) {
			return;
		}

		try {

			flock( $handle, LOCK_EX );
			$raw = stream_get_contents( $handle );
			$raw = $raw === false ? '' : $raw;

			// Keep only entries whose timestamp is still inside the window.
			$cutoff = time() - $this->retention_seconds();
			$kept = [];
			foreach ( explode( "\n", $raw ) as $line ) {
				if ( $line === '' ) {
					continue;
				}
				$decoded = json_decode( $line, true );
				$ts = is_array( $decoded ) && isset( $decoded['ts'] ) && is_int( $decoded['ts'] ) ? $decoded['ts'] : null;
				if ( $ts !== null && $ts > $cutoff ) {
					$kept[] = $line;
				}
			}

			// An emptied log is deleted outright and its path forgotten; otherwise the
			// surviving entries are rewritten only when the prune actually removed some.
			if ( count( $kept ) === 0 ) {
				flock( $handle, LOCK_UN );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the plugin's own log handle before unlinking it.
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the plugin's own emptied audit log from its scratch area.
				delete_option( self::OPTION );
				$this->remove_dir_if_empty( dirname( $path ) );
				return;
			}
			$new = implode( "\n", $kept ) . "\n";
			if ( strlen( $new ) !== strlen( $raw ) ) {
				ftruncate( $handle, 0 );
				rewind( $handle );
				fwrite( $handle, $new ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- rewriting the plugin's own log after rotation; see fopen above.
				fflush( $handle );
			}

		} finally {

			// The delete branch already closed the handle and returned; only reach here
			// for the keep/rewrite path, so release and close what is still open.
			if ( is_resource( $handle ) ) {
				flock( $handle, LOCK_UN );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the plugin's own log handle; see fopen above.
			}

		}

	}

	/**
	 * Deletes the audit folder outright, on uninstall.
	 *
	 * Removes the current log file, forgets its recorded path, and drops the whole
	 * audit directory and anything left in it — the single call uninstall reaches the
	 * audit residue through, scoped strictly to this plugin's own folder.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function purge(): void {

		// Forget the recorded path and remove every file the audit folder holds, then
		// the folder itself; a missing folder is simply nothing to remove.
		delete_option( self::OPTION );
		$dir = $this->dir_path();

		// Treat a symlinked audit folder as residue in itself: unlink the link rather
		// than follow it, so a link planted at the audit path can never redirect these
		// unlinks into a directory the folder does not own — is_dir() and scandir() would
		// both otherwise resolve through it.
		if ( is_link( $dir ) ) {
			unlink( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing a symlink planted at the plugin's own audit path on uninstall.
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = scandir( $dir );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$file = $dir . '/' . $entry;
			if ( is_file( $file ) || is_link( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the plugin's own audit residue on uninstall.
			}
		}
		$this->remove_dir_if_empty( $dir );

	}

	/**
	 * Appends one already-encoded JSON line to the current log under an exclusive lock.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line The JSON-encoded entry, without a trailing newline.
	 * @return void
	 *
	 * @throws RuntimeException When the log cannot be opened for appending.
	 */
	private function append( string $line ): void {

		// Open in append mode and hold an exclusive lock across the single write, so two
		// jobs reaching ready at once cannot interleave a partial line.
		$path = $this->ensure_path();
		$handle = fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- locked append to the plugin's own log; WP_Filesystem has no append-with-lock API.
		if ( $handle === false ) {
			throw new RuntimeException( 'Unable to open the audit log for appending.' );
		}
		try {
			flock( $handle, LOCK_EX );
			fwrite( $handle, $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- appending to the plugin's own log; see fopen above.
			fflush( $handle );
			flock( $handle, LOCK_UN );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the plugin's own log handle; see fopen above.
		}

	}

	/**
	 * Reads and decodes every entry from a log file, skipping any unreadable line.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path to the log file.
	 * @return list<array<array-key, mixed>> The decoded entries in file (append) order.
	 */
	private function read_all( string $path ): array {

		// Read the whole file under a shared lock so a concurrent append cannot be seen
		// half-written.
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- locked read of the plugin's own log; WP_Filesystem has no locking API.
		if ( $handle === false ) {
			return [];
		}
		try {
			flock( $handle, LOCK_SH );
			$raw = stream_get_contents( $handle );
			flock( $handle, LOCK_UN );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the plugin's own log handle; see fopen above.
		}

		// Decode one entry per line, dropping any line that is not a JSON object.
		$entries = [];
		foreach ( explode( "\n", $raw === false ? '' : $raw ) as $line ) {
			if ( $line === '' ) {
				continue;
			}
			$decoded = json_decode( $line, true );
			if ( is_array( $decoded ) ) {
				$entries[] = $decoded;
			}
		}

		return $entries;

	}

	/**
	 * Summarises a job's file selection without storing the full paths.
	 *
	 * The full paths are deliberately not recorded — only their count, total byte size,
	 * the distinct top-level roots they came from, and a SHA-256 of the sorted path list
	 * that lets an auditor confirm an exact set without the log itself disclosing it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $files The job's installation-root-relative file paths.
	 * @return array{count: int, bytes: int, roots: list<string>, sha256: string}
	 */
	private function files_summary( array $files ): array {

		// Total the on-disk bytes and collect the distinct top-level path segment of
		// each file (the whole name when it has no separator).
		$bytes = 0;
		$roots = [];
		foreach ( $files as $file ) {
			$abs = ( ! str_contains( $file, "\0" ) ) ? realpath( ABSPATH . '/' . $file ) : false;
			if ( $abs !== false && is_file( $abs ) ) {
				$size = filesize( $abs );
				if ( $size !== false ) {
					$bytes += $size;
				}
			}
			$top = strstr( $file, '/', true );
			$roots[ $top === false ? $file : $top ] = true;
		}

		// Hash the sorted path list so an auditor can verify the exact selection without
		// the log storing the paths themselves.
		$sorted = array_values( $files );
		sort( $sorted );
		$root_list = array_keys( $roots );
		sort( $root_list );

		return [
			'count' => count( $files ),
			'bytes' => $bytes,
			'roots' => $root_list,
			'sha256' => hash( 'sha256', implode( "\n", $sorted ) ),
		];

	}

	/**
	 * Resolves the current log path, minting a fresh randomly-named file when needed.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to the log file to append to.
	 */
	private function ensure_path(): string {

		// Reuse the recorded file while it exists; otherwise mint a fresh unguessable
		// name in the ensured audit folder and remember it.
		$recorded = get_option( self::OPTION );
		if ( is_string( $recorded ) && $recorded !== '' && is_file( $recorded ) ) {
			return $recorded;
		}
		$path = $this->ensure_dir() . '/' . bin2hex( random_bytes( 16 ) ) . '.jsonl';
		update_option( self::OPTION, $path, false );

		return $path;

	}

	/**
	 * Returns the recorded log path, or null when none is recorded.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The current log path, or null.
	 */
	private function current_path(): ?string {

		$recorded = get_option( self::OPTION );

		return is_string( $recorded ) && $recorded !== '' ? $recorded : null;

	}

	/**
	 * Resolves the audit folder's path under the uploads directory, without creating it.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to the audit folder, without a trailing slash.
	 */
	private function dir_path(): string {

		return rtrim( (string) ( wp_upload_dir()['basedir'] ?? '' ), '/' ) . '/' . self::DIR_NAME;

	}

	/**
	 * Resolves and creates the audit folder, silencing directory listing.
	 *
	 * The folder gets an index.html to silence listing but deliberately no server-config
	 * deny: the random file name, not an `.htaccess` that nginx ignores, is the control
	 * (ADR-0006).
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to the ensured audit folder.
	 */
	private function ensure_dir(): string {

		$dir = $this->dir_path();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$index = $dir . '/index.html';
		if ( ! is_file( $index ) ) {
			file_put_contents( $index, "<!-- Silence is golden. -->\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- silencing listing of the plugin's own scratch folder; WP_Filesystem would demand FTP credentials on some hosts.
		}

		return $dir;

	}

	/**
	 * Removes a directory only when it holds nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Absolute path of the directory to remove when empty.
	 * @return void
	 */
	private function remove_dir_if_empty( string $dir ): void {

		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = scandir( $dir );
		$entries = $entries === false ? [] : array_diff( $entries, [ '.', '..' ] );
		if ( count( $entries ) === 0 ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own emptied audit folder; see ADR-0006.
		}

	}

	/**
	 * Resolves the login name of a user id, or an empty string when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The job owner's WordPress user id.
	 * @return string The user's login, or '' when the user no longer exists.
	 */
	private function login_for( int $user_id ): string {

		$user = get_userdata( $user_id );

		return $user instanceof WP_User ? $user->user_login : '';

	}

	/**
	 * Resolves the retention window to seconds, from the constant then the filter.
	 *
	 * The window comes from the `KNTNT_EXTRACTOR_LOG_RETENTION_DAYS` constant, defaulting
	 * to 90 days, and the `kntnt_extractor_log_retention_days` filter then has the final
	 * word — the exact names ADR-0006 documents as the public override. A window of zero
	 * retains nothing; a non-numeric override falls back to the default.
	 *
	 * @since 0.1.0
	 *
	 * @return int The retention window in seconds (>= 0).
	 */
	private function retention_seconds(): int {

		$days = defined( 'KNTNT_EXTRACTOR_LOG_RETENTION_DAYS' ) ? constant( 'KNTNT_EXTRACTOR_LOG_RETENTION_DAYS' ) : self::DEFAULT_RETENTION_DAYS;

		/**
		 * Filters the audit-log retention window, in days.
		 *
		 * @since 0.1.0
		 *
		 * @param int|mixed $days The retention window in days.
		 */
		$days = apply_filters( 'kntnt_extractor_log_retention_days', $days );
		$days = is_numeric( $days ) ? (int) $days : self::DEFAULT_RETENTION_DAYS;

		return max( 0, $days ) * DAY_IN_SECONDS;

	}

	/**
	 * Resolves a `Y-m-d` lower bound to the start of that UTC day, or null.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $date The date string, or null.
	 * @return int|null The inclusive lower-bound timestamp, or null when unset/invalid.
	 */
	private function day_start( ?string $date ): ?int {

		if ( ! is_string( $date ) || $date === '' ) {
			return null;
		}
		$ts = strtotime( $date . ' 00:00:00 UTC' );

		return $ts === false ? null : $ts;

	}

	/**
	 * Resolves a `Y-m-d` upper bound to the end of that UTC day, or null.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $date The date string, or null.
	 * @return int|null The inclusive upper-bound timestamp, or null when unset/invalid.
	 */
	private function day_end( ?string $date ): ?int {

		if ( ! is_string( $date ) || $date === '' ) {
			return null;
		}
		$ts = strtotime( $date . ' 23:59:59 UTC' );

		return $ts === false ? null : $ts;

	}

}
