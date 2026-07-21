<?php
/**
 * Persistence for Extraction jobs: the working directory and its job-state files.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use RuntimeException;

/**
 * Creates, loads, and counts Extraction jobs on disk (ADR-0004, ADR-0008).
 *
 * A job must outlive the single request that created it — later, separate PHP
 * invocations resume it — so its state is a JSON file, not an in-memory value.
 * This store hides every filesystem concern behind a narrow interface: where the
 * working directory lives, how it is hardened, how a job id maps to a directory,
 * and how the one-non-terminal-job rule is evaluated. Callers create a job, find
 * one by id, or ask how many are still active, and never touch a path themselves.
 *
 * The working directory defaults to a dedicated folder under `wp_upload_dir()` —
 * the one location WordPress guarantees is writable and persistent — with one
 * randomly-named subdirectory per job, and is overridable to an outside-docroot
 * path through the `work_dir` Config knob (`KNTNT_EXTRACTOR_WORK_DIR` or its
 * filter). Because the uploads directory is ordinarily web-reachable, the
 * directory is hardened with an index.html and an .htaccess/web.config deny as
 * defence in depth; the artifact's encryption, not these files, is the primary
 * control (ADR-0008). The location is resolved through Config on every call so a
 * runtime override takes effect without reconstructing the store.
 *
 * @since 0.1.0
 */
final class Job_Store {

	/**
	 * Name of the dedicated working directory under the uploads directory.
	 *
	 * @since 0.1.0
	 */
	private const string DIR_NAME = 'kntnt-extractor';

	/**
	 * Basename of the per-job state file inside each job's directory.
	 *
	 * @since 0.1.0
	 */
	private const string STATE_FILE = 'job.json';

	/**
	 * The shape a job id — and therefore a job directory name — must match.
	 *
	 * A job id is 16 random bytes rendered as 32 lowercase hex characters. The
	 * pattern is also the guard that keeps a caller-supplied id from ever naming
	 * anything but a job directory: nothing outside this alphabet reaches a path.
	 *
	 * @since 0.1.0
	 */
	private const string ID_PATTERN = '/^[a-f0-9]{32}$/';

	/**
	 * Binds the store to the configuration seam it resolves its location through.
	 *
	 * @since 0.1.0
	 *
	 * @param Config $config The constant-then-filter configuration seam.
	 */
	public function __construct( private readonly Config $config ) {}

	/**
	 * Creates a queued job from an already-validated selection and persists it.
	 *
	 * Mints an unguessable id, writes the job's state file into a fresh randomly-
	 * named directory, and returns the job. The caller guarantees the inputs are
	 * resolved: the tables exist, the files are inside the root, and the key is
	 * canonical base64 of a 32-byte X25519 public key.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $owner      WordPress user id the job is bound to.
	 * @param string             $public_key Caller's ephemeral X25519 public key, as base64.
	 * @param array<int, string> $tables     Requested table names, already resolved.
	 * @param array<int, string> $files      Requested file paths, already resolved inside the root.
	 * @return Extraction_Job The persisted, queued job.
	 *
	 * @throws RuntimeException When the job's state file cannot be written whole.
	 */
	public function create( int $owner, string $public_key, array $tables, array $files ): Extraction_Job {

		// Resolve and harden the working directory, then mint an unguessable id and
		// build the queued record. The id doubles as the job's directory name; the
		// tick secret authenticates the internal driver, and the artifact filename is
		// its own unguessable token so the download path is not merely the job id.
		$base = $this->ensure_base();
		$id = bin2hex( random_bytes( 16 ) );
		$now = time();
		$job = new Extraction_Job( $id, Job_State::Queued, $owner, $public_key, array_values( $tables ), array_values( $files ), $now, $now, bin2hex( random_bytes( 32 ) ), bin2hex( random_bytes( 16 ) ) . '.sealed' );

		// Give the job its own directory, drop an index.html into it as defence in
		// depth, and persist the state file that lets a later request resume it.
		$dir = $base . '/' . $id;
		wp_mkdir_p( $dir );
		$this->write_file( $dir . '/index.html', $this->silence() );
		$this->persist( $job, $dir . '/' . self::STATE_FILE );

		return $job;

	}

	/**
	 * Loads the job with the given id, or null when there is no readable one.
	 *
	 * The id is validated against the id pattern before it is ever joined to a
	 * path, so a malformed or hostile id can never escape the working directory —
	 * it simply resolves to "no such job". A missing, unreadable, or unparseable
	 * state file is likewise reported as null rather than raised.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id The job identifier, typically straight from the URL.
	 * @return Extraction_Job|null The job, or null when none is readable.
	 */
	public function find( string $id ): ?Extraction_Job {

		// Refuse any id that is not the exact shape a job directory is named, so the
		// value can be joined to a path without a traversal check downstream.
		if ( preg_match( self::ID_PATTERN, $id ) !== 1 ) {
			return null;
		}

		// Read and decode the state file; every failure along the way — absent file,
		// unreadable bytes, non-JSON, or a record that does not reconstruct — is a
		// plain "no readable job here" at this deserialization boundary.
		$path = $this->base_path() . '/' . $id . '/' . self::STATE_FILE;
		if ( ! is_file( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own local state file, not a remote resource.
		$data = $raw === false ? null : json_decode( $raw, true );

		return is_array( $data ) ? Extraction_Job::from_array( $data ) : null;

	}

	/**
	 * Counts the jobs that still occupy the global concurrency slot.
	 *
	 * A job is active while its state is non-terminal (queued, running, or ready
	 * with an unconsumed artifact); a terminal job is finished and does not count.
	 * The walk considers only id-shaped subdirectories, so the hardening files and
	 * any stray entry are ignored, and an unreadable record is treated as absent.
	 *
	 * @since 0.1.0
	 *
	 * @return int The number of non-terminal jobs in the working directory.
	 */
	public function count_active(): int {

		// Nothing is active before the working directory has ever been created.
		$base = $this->base_path();
		if ( ! is_dir( $base ) ) {
			return 0;
		}

		// Tally every id-shaped directory whose readable job is still non-terminal.
		$entries = scandir( $base );
		$active = 0;
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( preg_match( self::ID_PATTERN, $entry ) !== 1 ) {
				continue;
			}
			$job = $this->find( $entry );
			if ( $job !== null && ! $job->state->is_terminal() ) {
				++$active;
			}
		}

		return $active;

	}

	/**
	 * Persists an updated job over its existing state file.
	 *
	 * The job's directory already exists — it was laid down by {@see create()} — so
	 * this rewrites only the state file, which is how every lifecycle transition
	 * (queued -> running -> ready and the terminal states) reaches disk.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job whose current state to persist.
	 * @return void
	 *
	 * @throws RuntimeException When the record cannot be encoded or written whole.
	 */
	public function save( Extraction_Job $job ): void {

		$this->persist( $job, $this->base_path() . '/' . $job->id . '/' . self::STATE_FILE );

	}

	/**
	 * Returns the absolute path the job's sealed artifact is written to and served from.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job whose artifact path to resolve.
	 * @return string Absolute path to the artifact inside the job's directory.
	 */
	public function artifact_path( Extraction_Job $job ): string {

		return $this->base_path() . '/' . $job->id . '/' . $job->artifact;

	}

	/**
	 * Returns the URL a ready job's artifact is downloaded from, or null.
	 *
	 * The artifact is a static file the web server serves directly (ADR-0004): safe
	 * to expose because it is sealed to the caller's key (ADR-0009), so this maps its
	 * on-disk path under the uploads directory to the matching public URL. It returns
	 * null for a job that is not yet ready, and for the outside-docroot override where
	 * the working directory is deliberately not web-reachable and has no static URL.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job whose download URL to resolve.
	 * @return string|null The artifact's public URL, or null when there is none.
	 */
	public function download_url( Extraction_Job $job ): ?string {

		// Nothing to serve until the artifact exists at the ready state.
		if ( $job->state !== Job_State::Ready ) {
			return null;
		}

		// Map the artifact's path to a URL only while it lives under the web-reachable
		// uploads directory; an outside-docroot working directory has no static URL.
		$uploads = wp_upload_dir();
		$basedir = rtrim( is_string( $uploads['basedir'] ?? null ) ? $uploads['basedir'] : '', '/' );
		$baseurl = rtrim( is_string( $uploads['baseurl'] ?? null ) ? $uploads['baseurl'] : '', '/' );
		$path = $this->artifact_path( $job );
		if ( $basedir === '' || ! str_starts_with( $path, $basedir . '/' ) ) {
			return null;
		}

		return $baseurl . substr( $path, strlen( $basedir ) );

	}

	/**
	 * Resolves the working directory's path without creating anything.
	 *
	 * The location comes from the `work_dir` Config knob — the constant
	 * `KNTNT_EXTRACTOR_WORK_DIR` or its filter — defaulting to a dedicated folder
	 * under the uploads directory. Resolved on each call so a runtime override
	 * takes effect immediately. A trailing slash is normalised away so every path
	 * derived from it is built the same way.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to the working directory, without a trailing slash.
	 */
	private function base_path(): string {

		// Default under uploads — the one guaranteed-writable, persistent location —
		// unless a non-empty override supplies an outside-docroot path instead.
		$default = wp_upload_dir()['basedir'] . '/' . self::DIR_NAME;
		$base = $this->config->get( 'work_dir', $default );

		return rtrim( is_string( $base ) && $base !== '' ? $base : $default, '/' );

	}

	/**
	 * Resolves the working directory, creating and hardening it when needed.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to the ensured, hardened working directory.
	 */
	private function ensure_base(): string {

		// Create the directory on first use, then lay down the three hardening files
		// if they are not already present. Both steps are idempotent, so a warm
		// directory costs only the existence checks.
		$base = $this->base_path();
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		$this->write_if_absent( $base . '/index.html', $this->silence() );
		$this->write_if_absent( $base . '/.htaccess', $this->htaccess_deny() );
		$this->write_if_absent( $base . '/web.config', $this->web_config_deny() );

		return $base;

	}

	/**
	 * Writes a hardening file only when it does not already exist.
	 *
	 * Existing files are left untouched so an operator who tightened the deny rules
	 * by hand is never overwritten on the next create.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path    Absolute path to write.
	 * @param string $content File body.
	 * @return void
	 */
	private function write_if_absent( string $path, string $content ): void {

		if ( ! is_file( $path ) ) {
			$this->write_file( $path, $content );
		}

	}

	/**
	 * Writes a file whole or fails loudly.
	 *
	 * A short or failed write leaves a truncated state file that a later request
	 * would misread as a corrupt job, so anything short of the full buffer is
	 * escalated rather than silently persisted — the same posture the crypto seam
	 * takes toward a partial container write. Direct filesystem I/O is used
	 * deliberately: the working directory is the plugin's own scratch area and must
	 * work on hosts where WP_Filesystem would demand FTP credentials.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path  Absolute path to write.
	 * @param string $bytes Buffer that must be written in full.
	 * @return void
	 *
	 * @throws RuntimeException When the file cannot be written in full.
	 */
	private function write_file( string $path, string $bytes ): void {

		// Treat a false return or a short count as a fatal write error.
		$written = file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the plugin's own local scratch area; WP_Filesystem would demand FTP credentials on some hosts.
		if ( $written === false || $written < strlen( $bytes ) ) {
			throw new RuntimeException( 'Unable to write the Extraction job file in full.' );
		}

	}

	/**
	 * Encodes a job to JSON and writes it to its state file whole.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job  The job to persist.
	 * @param string         $path Absolute path to the job's state file.
	 * @return void
	 *
	 * @throws RuntimeException When the record cannot be encoded or written whole.
	 */
	private function persist( Extraction_Job $job, string $path ): void {

		// Encode the record; a failure here means a caller-supplied file path carried
		// bytes that are not valid UTF-8, which cannot be stored as JSON — surface it
		// rather than persist a job file that is empty or half-written.
		$json = wp_json_encode( $job->to_array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( $json === false ) {
			throw new RuntimeException( 'Unable to encode the Extraction job state.' );
		}
		$this->write_file( $path, $json );

	}

	/**
	 * The body of an index.html that silences directory listing.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function silence(): string {
		return "<!-- Silence is golden. -->\n";
	}

	/**
	 * The body of an .htaccess that denies all direct web access, on Apache 2.2 and 2.4.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function htaccess_deny(): string {

		return <<<'HTACCESS'
			# Kntnt Extractor working directory: deny all direct web access.
			<IfModule mod_authz_core.c>
				Require all denied
			</IfModule>
			<IfModule !mod_authz_core.c>
				Order allow,deny
				Deny from all
			</IfModule>

			HTACCESS;

	}

	/**
	 * The body of a web.config that denies all direct web access on IIS.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function web_config_deny(): string {

		return <<<'WEBCONFIG'
			<?xml version="1.0" encoding="UTF-8"?>
			<configuration>
				<system.webServer>
					<authorization>
						<deny users="*" />
					</authorization>
				</system.webServer>
			</configuration>

			WEBCONFIG;

	}

}
