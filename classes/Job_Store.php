<?php
/**
 * Persistence for Extraction jobs: the working directory and its job-state files.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use Kntnt\Extractor\Crypto\Sealed_Writer;
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
 * It also enumerates the one thing that is never a job: a served artifact whose
 * record has been purged out from under it, so {@see Sweeper} can reclaim it
 * (ADR-0019).
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
 * A job's record is persisted as two files rather than one (ADR-0014). `job.json`
 * holds the selection — the requested tables and files, the only unbounded part of a
 * record — and is written at create and by nothing else except a tick that skipped a
 * vanished file, through {@see save_with_selection()} (ADR-0026). `state.json` holds
 * everything else and is what every {@see save()} rewrites, so its cost is a few
 * hundred bytes whatever the selection runs to. The two are read back together and
 * merged into the single record shape {@see Extraction_Job} defines, so nothing above
 * this class knows there are two.
 *
 * The state directory and the served artifact are deliberately kept apart. A job's
 * on-disk state (the tick secret, the plaintext table/file selection, the build's
 * position) stays in that deny-hardened, unguessably-named per-job directory, which no
 * public URL ever discloses. The finished artifact, by contrast, must be fetched directly
 * by the caller (ADR-0004) and so lives in a separate sibling *downloads* directory
 * that carries no deny and holds sealed artifacts only. That separation is a
 * security property, not a convenience: the two requirements — serve the artifact
 * statically on every web server, yet never expose the state — cannot both hold when
 * the artifact sits beside job.json under a single directory-level deny (which
 * Apache/IIS apply to the artifact too, while nginx ignores it and would serve
 * job.json). Keying the download link on the artifact's own random token rather than
 * the job id means a leaked link reveals nothing that could locate the state, and
 * the served artifact is safe in the open because it is sealed to the caller's key
 * (ADR-0009).
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
	 * Non-terminal jobs allowed at once when the knob does not override it.
	 *
	 * One global job by default (ADR-0004): an extraction is heavy, and a second
	 * concurrent one is refused with 429. Resolved through the Config seam under
	 * `max_active_jobs`, so a site raises it with the `KNTNT_EXTRACTOR_MAX_ACTIVE_JOBS`
	 * constant or the matching filter. It lives here, beside the count it bounds,
	 * rather than in the endpoint that refuses on it, so the ceiling and the count it
	 * is compared against cannot drift apart.
	 *
	 * @since 0.6.0
	 */
	private const int DEFAULT_MAX_ACTIVE_JOBS = 1;

	/**
	 * Suffix appended to the working directory to name the served downloads directory.
	 *
	 * The served artifacts live in a sibling of the state working directory, named by
	 * appending this suffix to the resolved working-directory path, so the two never
	 * share a directory yet a `work_dir` override still moves both together.
	 *
	 * @since 0.1.0
	 */
	private const string DOWNLOADS_SUFFIX = '-downloads';

	/**
	 * Basename of the per-job selection file inside each job's directory.
	 *
	 * Holds the unbounded, never-changing half of the record — the requested tables
	 * and files — and is written exactly once, by {@see create()}. Keeping it out of
	 * the file every save rewrites is what makes a save's cost independent of the
	 * selection's size (ADR-0014).
	 *
	 * @since 0.1.0
	 */
	private const string SELECTION_FILE = 'job.json';

	/**
	 * Basename of the per-job state file inside each job's directory.
	 *
	 * The half a save rewrites: the lifecycle state, the timestamps, the build's
	 * position, and the identity and key the record is driven by. Every field in it is
	 * a scalar, so the file stays a few hundred bytes for the life of any job however
	 * large its selection.
	 *
	 * @since 0.6.0
	 */
	private const string STATE_FILE = 'state.json';

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
	 * The shape an artifact's own filename must match.
	 *
	 * Minted by {@see create()} as 16 random bytes rendered hex plus a fixed
	 * `.sealed` suffix. {@see orphaned_artifacts()} matches every served-downloads
	 * entry against this pattern before treating it as a candidate at all, so the
	 * directory's own hardening file (`index.html`) and anything else that is not
	 * shaped like an artifact this store minted can never be reported as one,
	 * regardless of what else the enumeration finds.
	 *
	 * @since 0.6.0
	 */
	private const string ARTIFACT_PATTERN = '/^[a-f0-9]{32}\.sealed$/';

	/**
	 * Served-downloads entries one orphan scan examines at most.
	 *
	 * {@see orphaned_artifacts()} walks the served downloads directory to find an
	 * artifact no job record claims; this bounds that walk so a directory that has
	 * accumulated many entries cannot make one sweep cycle's disk work unbounded.
	 * The walk always restarts from the beginning of the directory rather than
	 * resuming where a previous call stopped, and `readdir()`'s order is not a
	 * documented, stable one — so on a directory holding more entries than this
	 * bound, an entry past it may go unexamined on this cycle, and nothing here
	 * guarantees a later cycle reaches it either. The bound trades that risk for a
	 * call that always returns promptly; it is a defensive cap on one call's disk
	 * work, not a claim that reclamation itself is complete.
	 *
	 * @since 0.6.0
	 */
	private const int ORPHAN_SCAN_LIMIT = 2000;

	/**
	 * How many extra times {@see find()} re-reads a present-but-unparseable state file.
	 *
	 * Defence in depth behind the atomic write in {@see write_file()}: a 404 must mean a
	 * job that is genuinely absent on disk, never one whose record a reader happened to
	 * sample mid-write (issue #20). With atomic publishing the state file is never partial,
	 * so this bounded re-read effectively never fires; it exists so any residual transient
	 * partial read is retried rather than reported as no such job.
	 *
	 * @since 0.2.2
	 */
	private const int FIND_RETRY_LIMIT = 3;

	/**
	 * Microseconds {@see find()} pauses between re-reads of an unparseable state file.
	 *
	 * @since 0.2.2
	 */
	private const int FIND_RETRY_DELAY_US = 2000;

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
	 * resolved: the tables exist, the files are inside the root, the key is
	 * canonical base64 of a 32-byte X25519 public key, and any requested chunk size
	 * is already inside the range the Config seam permits.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $owner          WordPress user id the job is bound to.
	 * @param string             $public_key     Caller's ephemeral X25519 public key, as base64.
	 * @param array<int, string> $tables         Requested full-data table names, already resolved.
	 * @param array<int, string> $structure_only Requested structure-only table names, already resolved (issue #16).
	 * @param array<int, string> $files          Requested file paths, already resolved inside the root.
	 * @param array<int, string> $skipped_files  Paths a `strict: false` create dropped because they no longer existed.
	 * @param int                $chunk_size     File-part budget in bytes the caller asked this job to package at, or 0 to package at the Config default. Already checked against the range the Config seam permits (issue #28); the stall adaptation halves it from here like any other per-job budget (ADR-0015).
	 * @param bool               $strict         Whether a vanished file is fatal to this job. Carried onto the record because the packaging path reads it too, not only the create filter (ADR-0026).
	 * @return Extraction_Job The persisted, queued job.
	 *
	 * @throws RuntimeException When the job's state file cannot be written whole.
	 */
	public function create( int $owner, string $public_key, array $tables, array $structure_only, array $files, array $skipped_files = [], int $chunk_size = 0, bool $strict = true ): Extraction_Job {

		// Resolve and harden the working directory, and lay down the separate served
		// downloads directory the ready artifact will be fetched from, then mint an
		// unguessable id and build the queued record. The id doubles as the job's
		// state directory name; the tick secret authenticates the internal driver, and
		// the artifact filename is its own unguessable token so the public download
		// path is keyed on the artifact, never on the job id.
		$base = $this->ensure_base();
		$this->ensure_downloads();
		$id = bin2hex( random_bytes( 16 ) );
		$now = time();
		$job = new Extraction_Job( $id, Job_State::Queued, $owner, $public_key, array_values( $tables ), array_values( $structure_only ), array_values( $files ), $now, $now, bin2hex( random_bytes( 32 ) ), bin2hex( random_bytes( 16 ) ) . '.sealed', chunk_size: $chunk_size, skipped_files: array_values( $skipped_files ), strict: $strict );

		// Give the job its own directory, drop an index.html into it as defence in
		// depth, and persist the two files that let a later request resume it. The
		// selection is written first and the state second, so the state file's presence
		// always implies a selection beside it and a create cut short mid-write reads as
		// a job that never existed rather than one with no selection.
		$dir = $base . '/' . $id;
		wp_mkdir_p( $dir );
		$this->write_file( $dir . '/index.html', $this->silence() );
		[ $selection, $state ] = $this->split( $job );
		$this->publish_json( $selection, $dir . '/' . self::SELECTION_FILE );
		$this->publish_json( $state, $dir . '/' . self::STATE_FILE );

		return $job;

	}

	/**
	 * Loads the job with the given id, or null when there is no readable one.
	 *
	 * The id is validated against the id pattern before it is ever joined to a
	 * path, so a malformed or hostile id can never escape the working directory —
	 * it simply resolves to "no such job". Null means a *verified* absence: a state
	 * file that is genuinely missing on disk. A file that is present but does not
	 * parse is re-read a bounded few times before it is ever reported as null, so a
	 * reader that samples the record mid-write never mistakes a live job for a
	 * vanished one (issue #20) — the atomic write in {@see write_file()} keeps the
	 * file whole and this retry is the defence in depth behind it.
	 *
	 * The record lives in two files (ADR-0014) and both are read here, then merged
	 * back into the single decoded shape {@see Extraction_Job::from_array()} validates,
	 * so the split costs the schema no second definition. Only the state file's absence
	 * counts as the job's: it is written last at create and never removed on its own, so
	 * its presence implies a selection beside it, and a selection file left without one
	 * is the residue of a create that never finished rather than a job.
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

		// Load both halves of the record, retrying a bounded few times when either is
		// present but does not parse, so only a genuinely missing state file ever reads
		// as no such job (see the method and FIND_RETRY_LIMIT docblocks for why).
		$dir = $this->base_path() . '/' . $id;
		$state_path = $dir . '/' . self::STATE_FILE;
		$selection_path = $dir . '/' . self::SELECTION_FILE;
		for ( $attempt = 0; $attempt <= self::FIND_RETRY_LIMIT; ++$attempt ) {

			// A state file that is not there is a verified absence — stop at once rather
			// than retry a job that genuinely does not exist.
			if ( ! is_file( $state_path ) ) {
				return null;
			}

			// Read and decode both halves and reconstruct the job from their union; a
			// whole pair returns immediately. Anything that does not parse is retried
			// until the budget is spent, after which it reads as no readable job here.
			$state = $this->read_json( $state_path );
			$selection = $this->read_json( $selection_path );
			$job = $state === null || $selection === null ? null : Extraction_Job::from_array( $state + $selection );
			if ( $job !== null ) {
				return $job;
			}

			// Pause briefly before re-reading, giving an in-flight rename time to land;
			// the last attempt falls straight through to the null below without waiting.
			if ( $attempt < self::FIND_RETRY_LIMIT ) {
				usleep( self::FIND_RETRY_DELAY_US );
				clearstatcache( true, $state_path );
				clearstatcache( true, $selection_path );
			}

		}

		return null;

	}

	/**
	 * Loads every readable job in the working directory.
	 *
	 * The walk considers only id-shaped subdirectories, so the hardening files and
	 * any stray entry are ignored, and a record that no longer reconstructs is
	 * treated as absent rather than surfaced. This is the single enumeration the
	 * concurrency count and the TTL sweep (ADR-0004) both read the live job set
	 * through, so "which jobs exist" is answered in exactly one place.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, Extraction_Job> Every readable job, in scandir order.
	 */
	public function all(): array {

		// Nothing exists before the working directory has ever been created.
		$base = $this->base_path();
		if ( ! is_dir( $base ) ) {
			return [];
		}

		// Reconstruct every id-shaped directory's job, skipping any entry that is not a
		// job directory and any record that no longer reads as a valid job.
		$entries = scandir( $base );
		$jobs = [];
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( preg_match( self::ID_PATTERN, $entry ) !== 1 ) {
				continue;
			}
			$job = $this->find( $entry );
			if ( $job !== null ) {
				$jobs[] = $job;
			}
		}

		return $jobs;

	}

	/**
	 * Counts the jobs that still occupy the global concurrency slot.
	 *
	 * A job is active while its state is non-terminal (queued, running, or ready
	 * with an unconsumed artifact); a terminal job is finished and does not count.
	 *
	 * @since 0.1.0
	 *
	 * @return int The number of non-terminal jobs in the working directory.
	 */
	public function count_active(): int {

		return count( array_filter( $this->all(), static fn( Extraction_Job $job ): bool => ! $job->state->is_terminal() ) );

	}

	/**
	 * Whether the site's concurrency ceiling leaves room for one more live job.
	 *
	 * The single answer to "may another job occupy the slot", so no caller carries its
	 * own idea of where the ceiling sits or how a misconfigured knob is clamped. It is
	 * asked in two situations: before claiming the slot, and — by a caller that has
	 * already committed a job into it — to confirm nothing else claimed it in the same
	 * window. `$already_taken` is what separates them: it discounts jobs the caller
	 * itself just made active, so the second question is not answered no by the
	 * caller's own job.
	 *
	 * @since 0.6.0
	 *
	 * @param int $already_taken Active jobs the caller has itself committed and may keep.
	 * @return bool True when another job fits under the ceiling.
	 */
	public function has_free_slot( int $already_taken = 0 ): bool {

		return ( $this->count_active() - $already_taken ) < $this->max_active_jobs();

	}

	/**
	 * Resolves the concurrency ceiling through the Config seam, clamped to at least one.
	 *
	 * A non-numeric or non-positive override cannot disable creation outright; the
	 * floor of one keeps the endpoint usable however the knob is misconfigured.
	 *
	 * @since 0.6.0
	 *
	 * @return int The maximum number of non-terminal jobs allowed at once.
	 */
	private function max_active_jobs(): int {

		$configured = $this->config->get( 'max_active_jobs', self::DEFAULT_MAX_ACTIVE_JOBS );

		return max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_MAX_ACTIVE_JOBS );

	}

	/**
	 * Persists an updated job over its existing state file.
	 *
	 * The job's directory already exists — it was laid down by {@see create()} — so
	 * this rewrites only the state file, which is how every lifecycle transition
	 * (queued -> running -> ready and the terminal states) reaches disk.
	 *
	 * The selection file is deliberately not rewritten: nothing that reaches here can
	 * have changed it, and rewriting it is exactly the cost this split exists to remove
	 * (ADR-0014). A build advancing a chunk saves twice, so this call is on the hottest
	 * path in the plugin and must stay proportional to nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job whose current state to persist.
	 * @return void
	 *
	 * @throws RuntimeException When the record cannot be encoded or written whole.
	 */
	public function save( Extraction_Job $job ): void {

		[ , $state ] = $this->split( $job );
		$this->publish_json( $state, $this->base_path() . '/' . $job->id . '/' . self::STATE_FILE );

	}

	/**
	 * Persists an updated job over both of its files, selection half included.
	 *
	 * The one write that does what {@see save()} deliberately refuses to. It exists for
	 * the single mutation that reaches the selection half after create: a tick that
	 * skipped a file it found gone appends to `skipped_files`, which lives there
	 * (ADR-0014/0026). ADR-0014's cost argument survives because this is paid per
	 * *skip* and never per save — the hot path stays {@see save()}, which is unchanged,
	 * and a run that skips nothing never reaches here at all.
	 *
	 * The halves are written in {@see create()}'s own order, selection first, so a tick
	 * killed between them leaves the skip recorded and the progress not yet past the
	 * file. The next tick re-reaches that file and re-skips it, which
	 * {@see Extraction_Job::with_skipped_file()} absorbs. The reverse order would
	 * risk the opposite: progress past a file whose skip was never written, leaving it
	 * missing from the artifact and from the report alike.
	 *
	 * @param Extraction_Job $job The job whose whole record to persist.
	 * @return void
	 *
	 * @throws RuntimeException When either half cannot be encoded or written whole.
	 */
	public function save_with_selection( Extraction_Job $job ): void {

		[ $selection, $state ] = $this->split( $job );
		$dir = $this->base_path() . '/' . $job->id;
		$this->publish_json( $selection, $dir . '/' . self::SELECTION_FILE );
		$this->publish_json( $state, $dir . '/' . self::STATE_FILE );

	}

	/**
	 * Deletes a job's artifact and its own working directory, scoped strictly to it.
	 *
	 * This is the single irreversible cleanup that consume, cancel, the TTL sweep
	 * (ADR-0004), and a create that lost the race for the concurrency slot all reach the
	 * disk through — the last of those purging a job it has just minted and handed to
	 * nobody, so the slot it took is given straight back. Exactly two things are removed
	 * and nothing else: the job's sealed artifact in the served downloads directory, and
	 * the job's own id-named state directory under the working directory. Every deletion
	 * is pinned to this one job — the artifact by its own unguessable token, the
	 * directory by its id-shaped name — and refuses to act on anything that resolves
	 * outside those two locations, so a `KNTNT_EXTRACTOR_WORK_DIR` relocation is
	 * honoured yet the delete can never escape to the shared working directory or
	 * beyond. A job cancelled before it ever reached ready simply has no artifact to
	 * remove. The audit record lives in its own file written earlier at the ready state
	 * (ADR-0006), so removing the job here never touches it.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job whose artifact and working directory to remove.
	 * @return void
	 */
	public function purge( Extraction_Job $job ): void {

		$this->delete_artifact( $job );
		$this->delete_work_dir( $job );

	}

	/**
	 * Removes every job and the working and served-downloads directories whole.
	 *
	 * The single call uninstall reaches all of this store's disk residue through
	 * (issue #13, ADR-0008). It first purges every still-known job — the same
	 * per-job artifact-and-directory cleanup {@see purge()} performs — then removes
	 * the working base directory and its served downloads sibling in their entirety,
	 * taking the hardening files (index.html, .htaccess/web.config) and any residual
	 * per-job directory with them. Both removals resolve their location through
	 * Config, so a `KNTNT_EXTRACTOR_WORK_DIR` override is honoured and both siblings
	 * are cleaned; each is bounded, realpath-guarded, strictly beneath its own parent
	 * exactly as {@see delete_tree()} enforces, so the walk can never escape the
	 * resolved directory. A directory that never existed is simply nothing to remove,
	 * so this is safe on a partially-present install.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function purge_all(): void {

		// Remove each still-known job's artifact and its own working directory first,
		// the same per-job cleanup every other purging actor reaches disk through.
		foreach ( $this->all() as $job ) {
			$this->purge( $job );
		}

		// Then remove the working base directory and its served downloads sibling in
		// full — each bounded strictly beneath its own parent, so the removal is pinned
		// to the resolved location and can never climb above it.
		$base = $this->base_path();
		$downloads = $this->downloads_path();
		$this->delete_tree( $base, dirname( $base ) );
		$this->delete_tree( $downloads, dirname( $downloads ) );

	}

	/**
	 * Returns the absolute path the job's sealed artifact is written to and served from.
	 *
	 * The artifact lives in the served downloads directory, not the deny-hardened
	 * state directory: it is meant to be fetched directly (ADR-0004) and is safe in
	 * the open because it is sealed to the caller's key (ADR-0009), whereas the state
	 * beside it must never be web-reachable. Its filename is the job's own unguessable
	 * artifact token, so the flat downloads directory needs no per-job subdirectory and
	 * the public path discloses no job id.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job whose artifact path to resolve.
	 * @return string Absolute path to the artifact inside the served downloads directory.
	 */
	public function artifact_path( Extraction_Job $job ): string {

		return $this->downloads_path() . '/' . $job->artifact;

	}

	/**
	 * Returns the path the in-progress sealed container is built at across ticks.
	 *
	 * The chunked build (ADR-0007) appends one bounded segment per tick, so the
	 * partially-built container must persist between ticks somewhere it is never
	 * web-reachable and never observed as a finished artifact. That is the job's own
	 * deny-hardened state directory — the same one that holds job.json — under a fixed
	 * basename derived from the artifact token, so every tick reopens the same file.
	 * Only at finalize is it published into the served downloads directory with the
	 * atomic rename {@see artifact_path()} names, so a ready poll never sees a partial
	 * container (ADR-0004/0008/0009).
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job whose in-progress container path to resolve.
	 * @return string Absolute path to the in-progress container inside the job's state directory.
	 */
	public function container_build_path( Extraction_Job $job ): string {

		return $this->base_path() . '/' . $job->id . '/' . $job->artifact . '.building';

	}

	/**
	 * Returns the path of the in-progress container's index sidecar.
	 *
	 * Derived from {@see container_build_path()} with the writer's own suffix so
	 * the two files stay siblings and a relocated working directory moves both.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The job whose sidecar path to resolve.
	 * @return string Absolute path to the index sidecar inside the job's state directory.
	 */
	public function container_index_path( Extraction_Job $job ): string {

		return $this->container_build_path( $job ) . Sealed_Writer::INDEX_SUFFIX;

	}

	/**
	 * Deletes a failed job's in-progress container and sidecar, keeping the record.
	 *
	 * A failed job is terminal in both directions (ADR-0024), so its partial
	 * container is residue the stranded-job sweep cannot see — `GET /extractions`
	 * lists only non-terminal jobs. Reclaiming the large files at fail-time is
	 * what empties the staging the next clone would otherwise select. The small
	 * record stays so a poll still reports `failed` with its reason.
	 *
	 * Each path is realpath-checked to sit inside this job's own directory, the
	 * same pin {@see delete_artifact()} uses, so a hand-edited token cannot
	 * climb out. Both halves of that pin are needed: the id names the directory
	 * and the artifact token names the file within it, and a null byte in either
	 * would make `realpath()` raise rather than answer, turning a failure the
	 * caller is recording into an uncaught throw.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The failed job whose staging to discard.
	 * @return void
	 */
	public function reclaim_staging( Extraction_Job $job ): void {

		// Refuse a malformed id so a hand-edited record cannot climb out of the store.
		if ( preg_match( self::ID_PATTERN, $job->id ) !== 1 ) {
			return;
		}

		// A null byte can never belong to a real filename and would make realpath raise
		// a ValueError; treat such a token as nothing to remove, exactly as
		// {@see delete_artifact()} does.
		if ( str_contains( $job->artifact, "\0" ) ) {
			return;
		}

		// Resolve the job directory; a vanished one is already reclaimed.
		$job_dir = realpath( $this->base_path() . '/' . $job->id );
		if ( $job_dir === false || ! is_dir( $job_dir ) ) {
			return;
		}

		// Unlink the container and sidecar only when they sit inside this job.
		foreach ( [ $this->container_build_path( $job ), $this->container_index_path( $job ) ] as $path ) {
			$resolved = realpath( $path );
			if ( $resolved !== false && str_starts_with( $resolved, $job_dir . '/' ) && is_file( $resolved ) ) {
				unlink( $resolved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the plugin's own in-progress container or sidecar after a non-resumable failure.
			}
		}

	}

	/**
	 * Enumerates served artifacts that no job record claims.
	 *
	 * A job's `artifact` filename is minted at {@see create()}, before the job ever
	 * runs, so for as long as any record of the job exists on disk that filename is
	 * claimed whatever state the job is in — queued, running, ready, or terminal.
	 * A file in the served downloads directory that no live record claims can
	 * therefore only be residue: the artifact-deletion half of a cancel or consume
	 * that landed mid-tick without the lock every purging caller now takes, or a
	 * crash in the narrow window between {@see Artifact_Builder::publish()}'s
	 * rename and the record settling. Such a file has no owner and can never again
	 * be reached through a job id, so this enumeration is the one seam that can
	 * find it at all; {@see Sweeper} is what reclaims what it finds, after its own
	 * grace period (ADR-0019).
	 *
	 * Every candidate is pinned exactly as {@see delete_artifact()} pins an
	 * artifact it already knows the name of: a null byte is rejected outright, the
	 * entry must match the shape {@see create()} mints ({@see ARTIFACT_PATTERN})
	 * before it is even resolved, and the resolved path must sit strictly inside
	 * the resolved downloads directory — so a symlink planted inside the directory,
	 * or an entry shaped like anything else (including this directory's own
	 * `index.html`), is never followed or reported. The walk itself is bounded to
	 * {@see ORPHAN_SCAN_LIMIT} entries, so a directory that has accumulated many
	 * files cannot make one call's disk work unbounded.
	 *
	 * @since 0.6.0
	 *
	 * @return array<int, string> Resolved absolute paths of orphaned artifacts.
	 */
	public function orphaned_artifacts(): array {

		// Nothing to enumerate before the served directory has ever been created.
		$downloads = realpath( $this->downloads_path() );
		if ( $downloads === false ) {
			return [];
		}

		// Every artifact filename a live job record still claims, whatever state
		// that job is in; a file this set names is not an orphan.
		$claimed = [];
		foreach ( $this->all() as $job ) {
			$claimed[ $job->artifact ] = true;
		}

		// Walk the directory once, bounded, collecting only a real file that is
		// shaped like an artifact, resolves strictly inside the downloads
		// directory, and is not claimed by a live job.
		$handle = opendir( $downloads ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir -- reading the plugin's own served downloads directory, not a filesystem write.
		if ( $handle === false ) {
			return [];
		}
		$orphans = [];
		$examined = 0;
		while ( $examined < self::ORPHAN_SCAN_LIMIT ) {
			$entry = readdir( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readdir -- reading the plugin's own served downloads directory, not a filesystem write.
			if ( $entry === false ) {
				break;
			}
			++$examined;
			if ( str_contains( $entry, "\0" ) || preg_match( self::ARTIFACT_PATTERN, $entry ) !== 1 || isset( $claimed[ $entry ] ) ) {
				continue;
			}
			$resolved = realpath( $downloads . '/' . $entry );
			if ( $resolved !== false && str_starts_with( $resolved, $downloads . '/' ) && is_file( $resolved ) ) {
				$orphans[] = $resolved;
			}
		}
		closedir( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir -- releasing the directory handle opened above.

		return $orphans;

	}

	/**
	 * Removes one orphaned artifact, re-checking it still sits inside the downloads directory.
	 *
	 * {@see orphaned_artifacts()} already pinned every path it returned, but a
	 * grace period ({@see Sweeper}) means real time — and potentially a whole
	 * further sweep cycle — passes between that enumeration and this call, so the
	 * pin is re-checked here rather than trusted from the earlier read. This is
	 * the same defence in depth {@see delete_artifact()} applies to a job-owned
	 * artifact, and for the same reason: never delete a path this call has not
	 * itself just proven sits where it is allowed to act.
	 *
	 * @since 0.6.0
	 *
	 * @param string $path Absolute path previously returned by {@see orphaned_artifacts()}.
	 * @return void
	 */
	public function delete_orphaned_artifact( string $path ): void {

		// A null byte can never belong to a real path and would make realpath raise
		// a ValueError; treat such a path as nothing to remove.
		if ( str_contains( $path, "\0" ) ) {
			return;
		}

		// Unlink only when the path still resolves to a real file genuinely inside
		// the served downloads directory — never one that has since moved outside it.
		$downloads = realpath( $this->downloads_path() );
		$resolved = realpath( $path );
		if ( $downloads !== false && $resolved !== false && str_starts_with( $resolved, $downloads . '/' ) && is_file( $resolved ) ) {
			unlink( $resolved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the plugin's own orphaned sealed artifact once its grace period has elapsed.
		}

	}

	/**
	 * Returns the path of the per-job lock a tick holds while it advances the build.
	 *
	 * The extraction driver is lock-free at the queue level (ADR-0007), so a
	 * continuation loopback and a status poll's nudge can fire against the same job at
	 * once. Both would otherwise reopen and append to the single in-progress container
	 * ({@see container_build_path()}), interleaving writes that corrupt already-sealed
	 * segments or clobber a finished job. A tick takes an exclusive advisory lock on
	 * this sibling file for its whole duration; a losing racer touches nothing and
	 * no-ops. It lives in the job's own deny-hardened state directory, never web-
	 * reachable and never published, and is removed with the directory when the job is
	 * purged. A dedicated file (rather than the container or job.json) is used so the
	 * lock outlives the rename that publishes the container and the rewrites that save
	 * the record.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job whose tick lock path to resolve.
	 * @return string Absolute path to the per-job tick lock file inside the state directory.
	 */
	public function container_lock_path( Extraction_Job $job ): string {

		return $this->base_path() . '/' . $job->id . '/' . $job->artifact . '.lock';

	}

	/**
	 * Takes the job's exclusive tick lock, returning the held handle or null.
	 *
	 * The single owner of the per-job advisory lock ({@see container_lock_path()}),
	 * so every actor that must not touch a job another is actively building through
	 * takes it the same way (ADR-0019): the tick driver serialising overlapping
	 * ticks, the TTL sweep refusing to purge a job a live tick holds, consume and
	 * cancel before purging a job the caller owns, and a create that lost the race
	 * for the concurrency slot before purging the job it has just minted and handed
	 * to nobody. A null return means the lock could not be taken — another actor
	 * holds it, or the lock file cannot even be opened — and the caller treats that
	 * uniformly as "someone else owns this right now, leave it alone" and no-ops.
	 * The non-blocking attempt never waits.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job to lock.
	 * @return resource|null The held lock handle to pass to {@see unlock()}, or null when it could not be taken.
	 */
	public function lock( Extraction_Job $job ) {

		// Open the job's lock file, creating it if absent, and try to take it without
		// blocking; hand the still-open handle back so the lock is held until unlock().
		// A path that cannot be opened at all is treated as "not acquirable", so the
		// caller no-ops rather than acting on a job whose lock state is unknown.
		$handle = fopen( $this->container_lock_path( $job ), 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- opening the plugin's own per-job advisory lock file, not a filesystem write.
		if ( $handle === false ) {
			return null;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releasing the lock handle when the lock is already held elsewhere.
			return null;
		}

		return $handle;

	}

	/**
	 * Releases the tick lock held on the given handle by closing it.
	 *
	 * Closing the descriptor is what releases the advisory lock — an explicit
	 * `flock( …, LOCK_UN )` first is redundant, and is deliberately avoided: the TTL
	 * sweep takes this lock on a job it is about to purge, so by the time it releases,
	 * the underlying lock file has already been deleted, and `LOCK_UN` on a handle to a
	 * removed file aborts under the WASM runtime the integration suite runs on. A plain
	 * close releases the lock on every platform and survives the deleted-file case.
	 *
	 * @since 0.1.0
	 *
	 * @param resource $handle The lock handle returned by {@see lock()}.
	 * @return void
	 */
	public function unlock( $handle ): void {

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the per-job advisory lock handle, which releases the lock.

	}

	/**
	 * Returns the URL a ready job's artifact is downloaded from, or null.
	 *
	 * The artifact is a static file the web server serves directly (ADR-0004): safe
	 * to expose because it is sealed to the caller's key (ADR-0009), so this maps its
	 * on-disk path in the served downloads directory to the matching public URL. That
	 * URL is keyed on the artifact's own unguessable token and carries no job id, so a
	 * leaked link discloses nothing that could locate the deny-hardened state directory
	 * (job.json, the tick secret, the plaintext selection) beside it. It returns null
	 * for a job that is not yet ready, and for the outside-docroot override where the
	 * downloads directory is deliberately not web-reachable and has no static URL.
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
		// uploads directory; an outside-docroot downloads directory has no static URL.
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
	 * Removes a job's sealed artifact from the served downloads directory.
	 *
	 * The artifact token names a single flat file in the downloads directory, and this
	 * touches nothing else: the path is resolved and re-checked to sit directly under
	 * the served directory before it is unlinked, so a token carrying a separator or a
	 * traversal — a hand-edited record — resolves outside and is skipped rather than
	 * followed. A job with no artifact yet is simply nothing to remove.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job whose artifact to remove.
	 * @return void
	 */
	private function delete_artifact( Extraction_Job $job ): void {

		// A null byte can never belong to a real filename and would make realpath raise
		// a ValueError; treat such a token as nothing to remove.
		if ( str_contains( $job->artifact, "\0" ) ) {
			return;
		}

		// Unlink the artifact only when it resolves to a real file genuinely inside the
		// served directory — never a path that escapes it.
		$downloads = realpath( $this->downloads_path() );
		$artifact = realpath( $this->downloads_path() . '/' . $job->artifact );
		if ( $downloads !== false && $artifact !== false && str_starts_with( $artifact, $downloads . '/' ) && is_file( $artifact ) ) {
			unlink( $artifact ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the plugin's own sealed artifact from its scratch area on consume/cancel/sweep.
		}

	}

	/**
	 * Removes a job's own id-named state directory, and nothing above it.
	 *
	 * The directory name must match the id pattern before it is ever joined to a path,
	 * so a malformed id can never climb out of the working directory; the recursive
	 * removal is then bounded to stay strictly beneath the working directory and never
	 * follows a symlink out of it.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job whose state directory to remove.
	 * @return void
	 */
	private function delete_work_dir( Extraction_Job $job ): void {

		// Only an id-shaped name reaches a path, pinning the deletion to this one job's
		// directory rather than the shared working directory that holds it.
		if ( preg_match( self::ID_PATTERN, $job->id ) !== 1 ) {
			return;
		}

		$this->delete_tree( $this->base_path() . '/' . $job->id, $this->base_path() );

	}

	/**
	 * Recursively removes a directory, bounded to stay strictly under a trusted root.
	 *
	 * A symlinked top-level path is treated as residue in itself — the link is unlinked
	 * and never followed — so a link planted at the directory this walk is aimed at can
	 * never redirect the irreversible removal into a directory it does not own. Below
	 * that, every level re-resolves the directory and refuses to act unless it sits
	 * strictly beneath the boundary, and it unlinks a symlink child rather than
	 * descending through it, so the walk can never escape the job's own directory even
	 * if a hostile entry were planted inside it. The boundary comparison runs on
	 * `wp_normalize_path()`'d separators and stays root-safe (a boundary of `/` still
	 * yields a usable `/` prefix), so the strict-beneath check holds on every platform —
	 * Windows realpath yields backslashes a forward-slash needle would otherwise miss.
	 * A path that is not a real directory is simply nothing to remove.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir      Absolute path of the directory to remove.
	 * @param string $boundary Absolute path the removal must stay strictly beneath.
	 * @return void
	 */
	private function delete_tree( string $dir, string $boundary ): void {

		// Treat a symlinked top-level path as residue in itself: unlink the link rather
		// than descend through it, so a link planted at the working or downloads path can
		// never redirect this walk into a sibling it does not own. The recursion below
		// already unlinks child links rather than following them, and never re-enters
		// here with a link, so this guard fires only for the caller-supplied top level.
		if ( is_link( $dir ) ) {
			unlink( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing a symlink planted at the plugin's own scratch path during cleanup.
			return;
		}

		// Refuse to touch anything that is not a real directory strictly beneath the
		// boundary, comparing on normalised separators so the check holds on Windows and
		// staying root-safe so a boundary of '/' still yields a usable prefix.
		$target = realpath( $dir );
		$root = realpath( $boundary );
		if ( $target === false || $root === false ) {
			return;
		}
		$target = wp_normalize_path( $target );
		$root = wp_normalize_path( $root );
		if ( ! str_starts_with( $target, rtrim( $root, '/' ) . '/' ) ) {
			return;
		}

		// Remove every child — descending only into a real subdirectory, never through a
		// symlink — then drop the now-empty directory itself.
		$entries = scandir( $target );
		foreach ( $entries === false ? [] : $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $target . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->delete_tree( $path, $root );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing an entry from the plugin's own job directory during cleanup.
			}
		}
		rmdir( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own emptied job directory during cleanup.

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
	 * Resolves the served downloads directory's path without creating anything.
	 *
	 * This is the state working directory's sibling — derived from the same resolved
	 * location, so a `work_dir` override moves both together — under a distinct name so
	 * a served artifact and a job's state never share a directory (ADR-0004/0008/0009).
	 * Under the default it sits beside the state directory in the uploads folder and is
	 * web-reachable; under an outside-docroot override it is not, and a ready job then
	 * has no static download URL.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to the served downloads directory, without a trailing slash.
	 */
	private function downloads_path(): string {

		return $this->base_path() . self::DOWNLOADS_SUFFIX;

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
	 * Resolves the served downloads directory, creating and softening it when needed.
	 *
	 * Unlike the state directory, this one is meant to be served: it gets an index.html
	 * to silence directory listing but deliberately NO .htaccess/web.config deny, so the
	 * sealed artifact it holds is fetched directly on Apache, IIS and nginx alike
	 * (ADR-0004). That is safe because every artifact here is sealed to the caller's key
	 * (ADR-0009) and no job state ever shares this directory. Creation is idempotent, so
	 * a warm directory costs only the existence checks.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to the ensured served downloads directory.
	 */
	private function ensure_downloads(): string {

		// Create the served directory on first use and silence listing; never write a
		// deny here — the artifact is meant to be served, and the deny lives only on the
		// state directory that must not be.
		$downloads = $this->downloads_path();
		if ( ! is_dir( $downloads ) ) {
			wp_mkdir_p( $downloads );
		}
		$this->write_if_absent( $downloads . '/index.html', $this->silence() );

		return $downloads;

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
	 * Publishes a file atomically — whole or not at all — or fails loudly.
	 *
	 * The buffer is written to a uniquely-named sibling in the same directory and then
	 * renamed over the target in one step. On a single filesystem POSIX rename is atomic,
	 * so a concurrent reader of the target sees either the whole previous file or the
	 * whole new one — never the zero-length or partial state a bare in-place
	 * file_put_contents leaves on every save (it opens with O_TRUNC). That in-place
	 * rewrite of the live state file is exactly what raced Job_Store::find() into spurious
	 * "no such job" 404s on a live, progressing job (issue #20), and the ADR-0010
	 * time-budgeted tick's write burst (issue #18) made the window easy to hit. This is
	 * the same discipline the artifact container already publishes with (Artifact_Builder).
	 *
	 * A short or failed write, or a rename that does not take, leaves only the temp
	 * sibling — never a truncated target — so the residue is removed and the failure
	 * escalated rather than a partial record silently persisted. Direct filesystem I/O is
	 * used deliberately: the working directory is the plugin's own scratch area and must
	 * work on hosts where WP_Filesystem would demand FTP credentials.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path  Absolute path to write.
	 * @param string $bytes Buffer that must be written in full.
	 * @return void
	 *
	 * @throws RuntimeException When the file cannot be written and published in full.
	 */
	private function write_file( string $path, string $bytes ): void {

		// Write the whole buffer to a uniquely-named sibling; a false return or a short
		// count is a fatal write error, so drop the residue and fail before publishing.
		$tmp = $path . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$written = file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the plugin's own local scratch area; WP_Filesystem would demand FTP credentials on some hosts.
		if ( $written === false || $written < strlen( $bytes ) ) {
			$this->discard( $tmp );
			throw new RuntimeException( 'Unable to write the Extraction job file in full.' );
		}

		// Swap the fully-written sibling over the target in one atomic step; a failed
		// rename leaves the previous target intact, so drop the residue and fail loudly.
		if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-directory publish of the plugin's own state file; WP_Filesystem::move offers no atomicity guarantee.
			$this->discard( $tmp );
			throw new RuntimeException( 'Unable to publish the Extraction job file into place.' );
		}

	}

	/**
	 * Removes a leftover temp file from a failed atomic write, best-effort.
	 *
	 * A failed write or rename in {@see write_file()} leaves only this temp sibling; it
	 * is removed so a failed save never litters the job directory. Whether the unlink
	 * itself succeeds is immaterial — the caller is already throwing on the failure that
	 * produced the residue — so the outcome is deliberately not checked.
	 *
	 * @since 0.2.2
	 *
	 * @param string $tmp Absolute path of the temp file to remove.
	 * @return void
	 */
	private function discard( string $tmp ): void {

		if ( is_file( $tmp ) ) {
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the plugin's own leftover temp file after a failed atomic write.
		}

	}

	/**
	 * Splits a job's record into the selection half and the state half.
	 *
	 * The line is {@see Extraction_Job::SELECTION_KEYS} — the unbounded fields — and
	 * the identity and schema version are copied into both, so either file names the
	 * job and the shape it was written in without the other beside it.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The job to split.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>} The selection
	 *         document and the state document, in that write order.
	 */
	private function split( Extraction_Job $job ): array {

		// Partition the record on the unbounded keys, then stamp the identity into both
		// halves so each is self-describing.
		$record = $job->to_array();
		$selection_keys = array_flip( Extraction_Job::SELECTION_KEYS );
		$identity = [
			'version' => $record['version'],
			'id' => $record['id'],
		];

		return [ array_intersect_key( $record, $selection_keys ) + $identity, array_diff_key( $record, $selection_keys ) ];

	}

	/**
	 * Encodes one half of a record to JSON and publishes it to the given path whole.
	 *
	 * The output is compact: the file is only ever machine-read, and pretty-printing it
	 * added about a seventh to the bytes of every write for no reader's benefit.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $document The document to persist.
	 * @param string               $path     Absolute path to write it to.
	 * @return void
	 *
	 * @throws RuntimeException When the document cannot be encoded or written whole.
	 */
	private function publish_json( array $document, string $path ): void {

		// Encode the document; a failure here means a caller-supplied file path carried
		// bytes that are not valid UTF-8, which cannot be stored as JSON — surface it
		// rather than persist a job file that is empty or half-written.
		$json = wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $json === false ) {
			throw new RuntimeException( 'Unable to encode the Extraction job state.' );
		}
		$this->write_file( $path, $json );

	}

	/**
	 * Reads and decodes one half of a record, or null when it is not readable.
	 *
	 * A missing, unreadable, or non-object file all read as null, which the caller
	 * treats uniformly as "this record does not reconstruct right now" and retries.
	 *
	 * @since 0.6.0
	 *
	 * @param string $path Absolute path to the document.
	 * @return array<array-key, mixed>|null The decoded document, or null.
	 */
	private function read_json( string $path ): ?array {

		$raw = is_file( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own local state file, not a remote resource.
		$data = $raw === false ? null : json_decode( $raw, true );

		return is_array( $data ) ? $data : null;

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
