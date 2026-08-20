<?php
/**
 * The persisted state of a single Extraction job.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * An immutable snapshot of one Extraction job as it lives on disk (ADR-0004).
 *
 * This is the shape every later stage of the pipeline reads and rewrites — the
 * execution, download-link, consume, cancel, sweep, and audit work all bind to
 * these fields — so it is defined once here as the single authoritative schema,
 * carried across the JSON boundary by {@see to_array()} and reconstructed by the
 * strict, null-returning {@see from_array()}. The record is deliberately closed
 * over exactly what a job needs to be resumed by a later, separate PHP request:
 * who owns it, what it selects, the key its artifact is sealed to, and where in
 * its lifecycle it is.
 *
 * The key is held as base64 rather than raw bytes so it survives JSON intact; the
 * crypto seam decodes it back to the 32 raw bytes it seals with. No private key
 * is ever part of this record — only the caller holds one (ADR-0009).
 *
 * @since 0.1.0
 */
final readonly class Extraction_Job {

	/**
	 * Version of the on-disk record shape.
	 *
	 * Written into every persisted job so a later plugin release can recognise
	 * and migrate an older record rather than misreading it. Bumped only when the
	 * field set or their meaning changes — raised to 2 when execution added the
	 * per-job tick secret and the sealed artifact's filename, to 3 when the
	 * chunked, resumable build added durable build-progress (ADR-0007), to 4
	 * when the last-progress timestamp made the sweep's absolute lifetime ceiling
	 * measure stalled progress rather than raw age, to 5 when the structure-only
	 * table selection (issue #16) joined the tables/files sets, to 6 when chunked
	 * table dumping (ADR-0013) added the mid-table row position to the build progress
	 * and the no-progress attempt counter and recorded failure reason to the job, to 7
	 * when the record was split across two files and the build progress traded its
	 * segment-name list for a count and an index offset (ADR-0014), and to 8 when a
	 * stall began persisting the per-job file-part, table-slice and table-fetch budgets
	 * it had adapted down to (ADR-0015). Still unreleased, the same version also carries
	 * the first-tick pre-raise and post-raise `memory_limit` and `max_execution_time`
	 * a later stall reason reads, the `skipped_files` a `strict: false` create
	 * records when a named file has vanished between the manifest walk and the POST,
	 * and the bounded `attempt_log` a tick records so a poll can answer what was
	 * begun (ADR-0016), so none of those needs a bump.
	 *
	 * @since 0.1.0
	 */
	public const int SCHEMA_VERSION = 8;

	/**
	 * Newest begun attempts the job record keeps.
	 *
	 * A last-N ring, not a lifetime log: every tick begins one attempt, and an
	 * unbounded list would grow with the build the way `segment_names` once did
	 * (ADR-0014). Eight is enough to see a stuck chunk retrying versus a run
	 * still advancing, and small enough that `state.json` stays a few hundred
	 * bytes (ADR-0016).
	 *
	 * @since 0.6.0
	 */
	public const int ATTEMPT_LOG_BOUND = 8;

	/**
	 * The record's keys that are persisted apart from the rest, in the selection file.
	 *
	 * These are the only fields whose size is unbounded — a selection runs to
	 * tens of thousands of paths — and the only ones no lifecycle transition ever
	 * changes. Splitting exactly them out is what lets a save rewrite a few hundred
	 * bytes instead of megabytes, without any field changing its name or meaning
	 * (ADR-0014). The split is a persistence concern, so {@see Job_Store} performs it;
	 * the line is drawn here because which fields are unbounded is schema knowledge.
	 *
	 * @since 0.6.0
	 *
	 * @var list<string>
	 */
	public const array SELECTION_KEYS = [ 'tables', 'structure_only', 'files', 'skipped_files' ];

	/**
	 * Builds a job record from its fully-resolved fields.
	 *
	 * The caller supplies already-validated values: the id is the unguessable
	 * directory name, the selections have been checked for existence, and the key
	 * is canonical base64 of a 32-byte X25519 public key.
	 *
	 * The tick secret authenticates the internal tick endpoint that drives the job
	 * forward — it is an authorization token for the loopback driver, never a key
	 * that can open the sealed artifact, so persisting it does not weaken the seal
	 * (ADR-0009). The artifact is the unguessable filename the sealed container is
	 * written to and served from once the job is ready.
	 *
	 * @since 0.1.0
	 *
	 * @param string                    $id          Unguessable job identifier; also its directory name.
	 * @param Job_State                 $state       Lifecycle state the job is in.
	 * @param int                       $owner       WordPress user id the job is bound to.
	 * @param string                    $public_key  Caller's ephemeral X25519 public key, as base64.
	 * @param array<int, string>        $tables      Requested full-data table names, already resolved to existing tables.
	 * @param array<int, string>        $structure_only Requested structure-only (DDL, no rows) table names, already resolved to existing tables (issue #16).
	 * @param array<int, string>        $files       Requested file paths, already resolved inside the root.
	 * @param int                       $created_at  Unix timestamp the job was created at.
	 * @param int                       $updated_at  Unix timestamp the job last changed state at.
	 * @param string                    $tick_secret Per-job secret authenticating the internal tick endpoint.
	 * @param string                    $artifact    Unguessable filename of the sealed artifact in the job directory.
	 * @param Build_Progress|null       $progress   How far the chunked build has got, or null before it begins.
	 * @param int|null                  $progressed_at Unix timestamp the build last advanced a chunk, or null before it has (treated as the creation time). Distinct from $updated_at, which every state save refreshes: this moves only on real progress, so the sweep's absolute ceiling can tell a slow-but-advancing large job from one whose chunk fails uncatchably every attempt.
	 * @param int                       $attempts    Chunk attempts begun since the build last advanced, reset to zero by every real advance. The counter the tick driver bounds a chunk that dies uncatchably with (ADR-0013), so a job whose tick is killed from outside PHP fails rather than retrying forever.
	 * @param string|null               $error       Why the job failed, when the plugin diagnosed the failure itself, or null. Surfaced verbatim to the polling owner, so it carries no filesystem path, SQL, or other internal detail.
	 * @param int                       $chunk_size  Per-job file-part budget in bytes, or 0 to use the Config default. Set at create when the caller asked for one on `POST /extractions` (issue #28), and overwritten when a stall halves it (ADR-0015) so the next tick — and a resume of a failed job — packages a smaller part rather than walking back into the same wall. The two are the same field on purpose: a requested size is a starting point the adaptation is free to walk down from, not a floor under it.
	 * @param int                       $table_chunk_bytes Per-job table-slice byte budget, or 0 to use the Config default. The table-side counterpart of $chunk_size.
	 * @param int                       $table_chunk_rows Per-job upper bound on rows fetched for one table slice, or 0 to use the Config default. Halved alongside $table_chunk_bytes, because the byte budget bounds only what is rendered while this bounds what `$wpdb->get_results()` materialises — the half a table stall is most likely to have died in (ADR-0015).
	 * @param bool                      $budget_keys_present Whether the schema-8 budget keys were on the record this snapshot came from. True for every object this release constructs; false only when {@see from_array()} rebuilt a 0.5.1-or-earlier write that never had them. Not itself a persisted field — {@see to_array()} uses it to omit the keys again, so a save cannot turn a pre-adaptation stall into a this-release zero-budget write.
	 * @param string|null               $host_memory_limit The host's `memory_limit` before the first tick asked for more, or null until that tick has run. The stall reason reads this rather than the live process, so a later tick still names the pair in force when the chunk died.
	 * @param string|null               $host_max_execution_time The host's `max_execution_time` before the first tick asked for more, or null until that tick has run.
	 * @param string|null               $raised_memory_limit The `memory_limit` in force after the first tick's ask, or null until that tick has run.
	 * @param string|null               $raised_max_execution_time The `max_execution_time` in force after the first tick's ask, or null until that tick has run.
	 * @param array<int, string>        $skipped_files Install-root-relative paths a `strict: false` create dropped because they no longer existed. Empty when nothing was skipped. Written once, with the selection.
	 * @param array<int, Chunk_Attempt> $attempt_log Newest begun chunk attempts, oldest first, capped at {@see ATTEMPT_LOG_BOUND}. Empty until the first tick. A debug surface, not the stall counter (ADR-0016).
	 */
	public function __construct(
		public string $id,
		public Job_State $state,
		public int $owner,
		public string $public_key,
		public array $tables,
		public array $structure_only,
		public array $files,
		public int $created_at,
		public int $updated_at,
		public string $tick_secret,
		public string $artifact,
		public ?Build_Progress $progress = null,
		public ?int $progressed_at = null,
		public int $attempts = 0,
		public ?string $error = null,
		public int $chunk_size = 0,
		public int $table_chunk_bytes = 0,
		public int $table_chunk_rows = 0,
		public bool $budget_keys_present = true,
		public ?string $host_memory_limit = null,
		public ?string $host_max_execution_time = null,
		public ?string $raised_memory_limit = null,
		public ?string $raised_max_execution_time = null,
		public array $skipped_files = [],
		public array $attempt_log = [],
	) {}

	/**
	 * Returns a copy of the job in a new lifecycle state, stamped as just updated.
	 *
	 * The record is immutable, so a state transition mints a fresh instance rather
	 * than mutating this one. The updated-at stamp is refreshed to now, which is
	 * what lets a later reader tell an actively-running job from a stalled one.
	 *
	 * @since 0.1.0
	 *
	 * @param Job_State $state The lifecycle state to move the job into.
	 * @return self A new record identical to this one but in the given state.
	 */
	public function with_state( Job_State $state ): self {

		return new self( $this->id, $state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts, $this->error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->budget_keys_present, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log );

	}

	/**
	 * Returns a copy that has begun one more chunk attempt, stamped as just updated.
	 *
	 * Counted BEFORE the chunk runs and persisted immediately, which is the whole point
	 * (ADR-0013): a chunk that exhausts the host's memory limit or execution time is
	 * killed outside PHP, so nothing after it runs and no `catch` can record anything.
	 * A count taken beforehand survives that kill, and the next tick reads it as
	 * evidence that the previous one died where it stood. {@see with_progress()} clears
	 * it again on every real advance, so the count is always consecutive attempts since
	 * the last progress, never a lifetime total.
	 *
	 * The same moment appends a {@see Chunk_Attempt} onto the bounded attempt log
	 * (ADR-0016), so a later poll can name the chunks that were begun even when the
	 * stall counter has since been reset. The log is last-N, never a lifetime total.
	 *
	 * @since 0.4.0
	 *
	 * @return self A new record identical to this one but counting one more attempt.
	 */
	public function with_attempt(): self {

		// Append this begin onto the last-N log, dropping the oldest once full.
		$log = [ ...$this->attempt_log, Chunk_Attempt::begun_on( $this ) ];
		if ( count( $log ) > self::ATTEMPT_LOG_BOUND ) {
			$log = array_slice( $log, -self::ATTEMPT_LOG_BOUND );
		}

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts + 1, $this->error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->budget_keys_present, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $log );

	}

	/**
	 * Returns a copy failed with a caller-visible reason, stamped as just updated.
	 *
	 * Reserved for a failure the plugin can describe safely, which is both of the two
	 * it can see at all: the stalled build (ADR-0013) composes its reason from the
	 * caller's own selection and two settings `GET /environment` already discloses,
	 * and an unexpected throw composes one naming the throwable, bounded and
	 * trace-free so the arbitrary string it relays stays within what the poll may
	 * return (issue #25). What still leaves the reason null is a failure no `catch`
	 * ever reaches — a process killed outright — and after those two that absence is
	 * itself the diagnosis rather than a gap.
	 *
	 * @since 0.4.0
	 *
	 * @param string $error Why the job failed, phrased for the polling owner to act on.
	 * @return self A new record identical to this one but failed and carrying the reason.
	 */
	public function with_failure( string $error ): self {

		return new self( $this->id, Job_State::Failed, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts, $error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->budget_keys_present, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log );

	}

	/**
	 * Returns a copy carrying advanced build-progress, stamped as just updated.
	 *
	 * A tick that packages one bounded chunk records the point it reached this way,
	 * leaving the job in its current state (running) with a fresh heartbeat so a
	 * concurrent poll can tell it is actively progressing (ADR-0007). The
	 * last-progress timestamp is stamped to now here — and only here — so the sweep's
	 * absolute ceiling measures how long the build has been STALLED, not merely how
	 * long the job has existed: a large job advancing a chunk per cron cycle keeps
	 * this fresh and is spared, while one whose chunk dies uncatchably every attempt
	 * never reaches this point and is eventually reclaimed.
	 *
	 * Reaching here is also what clears the attempt counter: a chunk that completed is
	 * proof the previous attempts, however many, were not a permanent stall (ADR-0013).
	 *
	 * @since 0.1.0
	 *
	 * @param Build_Progress $progress The progress the latest chunk reached.
	 * @return self A new record identical to this one but carrying the progress.
	 */
	public function with_progress( Build_Progress $progress ): self {

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $progress, time(), 0, $this->error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->budget_keys_present, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log );

	}

	/**
	 * Returns a copy carrying adapted packaging budgets and a cleared attempt counter.
	 *
	 * The stall path (ADR-0015) calls this instead of failing the job when a chunk has
	 * died at the same offset enough times and the relevant budget can still shrink.
	 * Attempts reset so the new size gets a fresh window; the budgets persist so a
	 * later tick — or a resume after the job has left `running` — packages the smaller
	 * part rather than rediscovering the same wall. Writing the budgets also
	 * stamps the schema-8 keys present, so a save cannot drop them from a
	 * keys-absent record.
	 *
	 * @since 0.6.0
	 *
	 * @param Chunk_Budgets $budgets The adapted bounds to persist.
	 * @return self A new record identical to this one but with the budgets and no attempts.
	 */
	public function with_budgets( Chunk_Budgets $budgets ): self {

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, 0, $this->error, $budgets->file_bytes, $budgets->table_bytes, $budgets->table_rows, true, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log );

	}

	/**
	 * Returns a copy re-entered into `running` from `failed`, with a fresh attempt window.
	 *
	 * Reserved for a diagnosed stall an earlier release recorded before it could adapt
	 * (ADR-0015): the job still holds the container and the progress a resume needs, and
	 * the budgets handed in here are already smaller than the ones it died on. The
	 * failure reason is cleared because the job is no longer failed. An opaque throw, and
	 * a stall this release already adapted, stay failed and never reach here.
	 * This is also the moment the schema-8 budget keys are written onto a
	 * record that never had them, so a save cannot omit them and a second
	 * failure is not resumable.
	 *
	 * @since 0.6.0
	 *
	 * @param Chunk_Budgets $budgets The adapted bounds to persist across the resume.
	 * @return self A new record identical to this one but running, with no attempts or error.
	 */
	public function with_resume( Chunk_Budgets $budgets ): self {

		return new self( $this->id, Job_State::Running, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, 0, null, $budgets->file_bytes, $budgets->table_bytes, $budgets->table_rows, true, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log );

	}

	/**
	 * Whether a stall has ever shrunk this job's packaging budgets.
	 *
	 * False means the record still packages at the Config defaults — either because
	 * nothing has stalled yet, or because it was written by a release that failed a
	 * stall outright instead of adapting around it. The resume path (ADR-0015) reads it
	 * as exactly that second thing, which is what keeps an automatic resume from ever
	 * re-driving a job back into a wall this release has already measured.
	 *
	 * @since 0.6.0
	 *
	 * @return bool True once any of the three budgets has been adapted down.
	 */
	public function has_adapted_budgets(): bool {

		return $this->chunk_size > 0 || $this->table_chunk_bytes > 0 || $this->table_chunk_rows > 0;

	}

	/**
	 * Returns a copy carrying the first tick's pre-raise and post-raise host limits.
	 *
	 * Written once, when the first tick measures them. A later tick's stall reason
	 * reads this pair from the record rather than from the live process, so a run
	 * that adapts over hours still describes the limits in force when the chunk
	 * died (ADR-0015).
	 *
	 * @since 0.6.0
	 *
	 * @param string $host_memory_limit The host's `memory_limit` before the ask.
	 * @param string $host_max_execution_time The host's `max_execution_time` before the ask.
	 * @param string $raised_memory_limit The `memory_limit` in force after the ask.
	 * @param string $raised_max_execution_time The `max_execution_time` in force after the ask.
	 * @return self A new record identical to this one but carrying the measured pair.
	 */
	public function with_host_limits( string $host_memory_limit, string $host_max_execution_time, string $raised_memory_limit, string $raised_max_execution_time ): self {

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts, $this->error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->budget_keys_present, $host_memory_limit, $host_max_execution_time, $raised_memory_limit, $raised_max_execution_time, $this->skipped_files, $this->attempt_log );

	}

	/**
	 * Whether this record already carries the first tick's measured host-limit pair.
	 *
	 * False until that tick has run. The driver uses it to persist the pair once
	 * and leave later ticks alone, so a stall reason cannot pick up a later
	 * process's numbers.
	 *
	 * @since 0.6.0
	 *
	 * @return bool True once all four readings have been persisted.
	 */
	public function has_recorded_limits(): bool {

		return $this->host_memory_limit !== null
			&& $this->host_max_execution_time !== null
			&& $this->raised_memory_limit !== null
			&& $this->raised_max_execution_time !== null;

	}

	/**
	 * Whether this record is the stranded stall a resume exists for (ADR-0015).
	 *
	 * That is a failure a release too old to adapt wrote: it diagnosed a stall — so
	 * it carries a reason — and the schema-8 budget keys are absent, because that
	 * release never wrote them. Every failure *this* release writes is one of the
	 * other three shapes: an unexpected throw (a reason naming the throwable, the
	 * keys present), a stall it already shrank its way to the floor over (adapted
	 * budgets), or a chunk whose bounds it could never shrink at all, which is
	 * failed with the keys present at zero and the container already discarded.
	 *
	 * The signal is the absence of those keys, not their values. This release always
	 * stamps them — as zero while the job still packages at the Config defaults — so
	 * a structure-only or index stall is not this shape even though
	 * {@see has_adapted_budgets()} is also false for it.
	 *
	 * This is the one definition of that record shape, because two callers must
	 * agree about it exactly: the {@see Dispatcher} re-drives it, and the TTL sweep
	 * spares it while reclaiming every other failed record. A disagreement would
	 * have the sweep delete the very container the resume is for.
	 *
	 * @since 0.6.0
	 *
	 * @return bool True when this is a pre-adaptation stall record.
	 */
	public function is_pre_adaptation_stall(): bool {

		return $this->state === Job_State::Failed && $this->error !== null && ! $this->budget_keys_present;

	}

	/**
	 * Serialises the job into the associative array persisted as JSON.
	 *
	 * The state crosses the boundary as its backed string value, and the schema
	 * version is stamped in so a reader can tell which shape it is looking at. This is
	 * the whole record; {@see Job_Store} splits it across two files on the
	 * {@see SELECTION_KEYS} line and merges them back before {@see from_array()} sees
	 * it, so the schema has one definition however many files hold it.
	 *
	 * @since 0.1.0
	 *
	 * @return array{version: int, id: string, state: string, owner: int, public_key: string, tables: array<int, string>, structure_only: array<int, string>, files: array<int, string>, skipped_files?: array<int, string>, created_at: int, updated_at: int, tick_secret: string, artifact: string, progress: array{tables_done: int, structure_done: int, file_index: int, file_offset: int, container_bytes: int, index_bytes: int, segment_count: int, file_size: int|null, file_mtime: int|null, table_offset: int, table_cursor: array<int, string>|null}|null, progressed_at: int|null, attempts: int, error: string|null, chunk_size?: int, table_chunk_bytes?: int, table_chunk_rows?: int, host_memory_limit?: string, host_max_execution_time?: string, raised_memory_limit?: string, raised_max_execution_time?: string, attempt_log?: list<array{at: int, kind: string, n: int, offset: int}>}
	 */
	public function to_array(): array {

		$record = [
			'version' => self::SCHEMA_VERSION,
			'id' => $this->id,
			'state' => $this->state->value,
			'owner' => $this->owner,
			'public_key' => $this->public_key,
			'tables' => $this->tables,
			'structure_only' => $this->structure_only,
			'files' => $this->files,
			'created_at' => $this->created_at,
			'updated_at' => $this->updated_at,
			'tick_secret' => $this->tick_secret,
			'artifact' => $this->artifact,
			'progress' => $this->progress?->to_array(),
			'progressed_at' => $this->progressed_at,
			'attempts' => $this->attempts,
			'error' => $this->error,
		];

		// A this-release write always stamps the schema-8 budget keys, even when they
		// are still zero (Config defaults). An older record that never had them must
		// keep that absence, because it is the resume/sweep signal (ADR-0015).
		if ( $this->budget_keys_present ) {
			$record['chunk_size'] = $this->chunk_size;
			$record['table_chunk_bytes'] = $this->table_chunk_bytes;
			$record['table_chunk_rows'] = $this->table_chunk_rows;
		}

		// The first-tick limit pair is written only once it has been measured. An
		// older schema-8 record, or a job that has not been ticked yet, keeps the
		// keys absent rather than inventing empty readings.
		if ( $this->host_memory_limit !== null ) {
			$record['host_memory_limit'] = $this->host_memory_limit;
		}
		if ( $this->host_max_execution_time !== null ) {
			$record['host_max_execution_time'] = $this->host_max_execution_time;
		}
		if ( $this->raised_memory_limit !== null ) {
			$record['raised_memory_limit'] = $this->raised_memory_limit;
		}
		if ( $this->raised_max_execution_time !== null ) {
			$record['raised_max_execution_time'] = $this->raised_max_execution_time;
		}

		// Skipped files are a later addition to unreleased schema 8; omit the key
		// when nothing was skipped so a job that never used strict: false writes
		// the same record it always did.
		if ( $this->skipped_files !== [] ) {
			$record['skipped_files'] = $this->skipped_files;
		}

		// The attempt log is a later addition to unreleased schema 8; omit the key
		// when nothing has been begun so a job that has not been ticked writes the
		// same record it always did (ADR-0016).
		if ( $this->attempt_log !== [] ) {
			$serialized = [];
			foreach ( $this->attempt_log as $attempt ) {
				$serialized[] = $attempt->to_array();
			}
			$record['attempt_log'] = $serialized;
		}

		return $record;

	}

	/**
	 * Reconstructs a job from a decoded JSON record, or null when it is not one.
	 *
	 * The decoded array is an untrusted deserialization boundary — a truncated
	 * write, a hand-edited file, or a record from an incompatible future schema
	 * can all reach here — so every field is checked and an unrecognisable record
	 * yields null rather than a half-populated object. A caller reading a job
	 * directory treats null as "no readable job here".
	 *
	 * @since 0.1.0
	 *
	 * @param array<array-key, mixed> $data The `json_decode( ..., true )` of a job file.
	 * @return self|null The reconstructed job, or null when the record is unusable.
	 */
	public static function from_array( array $data ): ?self {

		// Map the persisted state string back to a case; an unknown value (a newer
		// state this release does not know) makes the whole record unreadable here.
		$state = is_string( $data['state'] ?? null ) ? Job_State::tryFrom( $data['state'] ) : null;

		// Narrow the scalar identity and ownership fields; any missing or ill-typed
		// one disqualifies the record.
		$id = $data['id'] ?? null;
		$owner = $data['owner'] ?? null;
		$public_key = $data['public_key'] ?? null;
		$tables = self::string_list( $data['tables'] ?? null );
		$files = self::string_list( $data['files'] ?? null );

		// The structure-only selection is a schema-5 addition (issue #16); a record
		// written before it simply carries none, so an absent value reads as the empty
		// selection rather than disqualifying the record. A present-but-ill-typed value,
		// by contrast, is a malformed selection and yields null, which fails the record.
		$structure_only = array_key_exists( 'structure_only', $data ) ? self::string_list( $data['structure_only'] ) : [];
		$created_at = $data['created_at'] ?? null;
		$updated_at = $data['updated_at'] ?? null;
		$tick_secret = $data['tick_secret'] ?? null;
		$artifact = $data['artifact'] ?? null;

		// Build-progress is a schema-3 addition; an older record (or one whose build
		// has not begun) simply carries none, so its absence is never disqualifying.
		$progress = array_key_exists( 'progress', $data ) && $data['progress'] !== null ? Build_Progress::from_array( $data['progress'] ) : null;

		// The last-progress timestamp is a schema-4 addition; an older record (or one
		// that has not progressed) carries none, and the sweep treats a null as the
		// creation time. A present-but-ill-typed value is read as absent rather than
		// disqualifying the whole record, so a hand-edited stamp never makes a live job
		// unreadable — the ceiling simply falls back to the creation time for it.
		$progressed_at = ( isset( $data['progressed_at'] ) && is_int( $data['progressed_at'] ) && $data['progressed_at'] >= 0 ) ? $data['progressed_at'] : null;

		// The attempt counter and the failure reason are schema-6 additions (ADR-0013);
		// an older record carries neither, so an absent counter reads as no attempts yet
		// and an absent reason as none recorded. Both are read leniently, like the
		// timestamp above: a hand-edited value falls back to its default rather than
		// making a live job unreadable, since neither can corrupt an artifact — the
		// counter merely costs one more attempt before the stall is called.
		$attempts = ( isset( $data['attempts'] ) && is_int( $data['attempts'] ) && $data['attempts'] >= 0 ) ? $data['attempts'] : 0;
		$error = is_string( $data['error'] ?? null ) ? $data['error'] : null;

		// The per-job packaging budgets are a schema-8 addition (ADR-0015); an older
		// record carries none of them, so an absent value reads as "use the Config
		// default" rather than disqualifying the record. That absence is also what
		// identifies a pre-adaptation stall ({@see is_pre_adaptation_stall()}): it is
		// remembered here so a later save of the same record can omit the keys again,
		// rather than writing zeroes that would look like a this-release write. A
		// present-but-ill-typed value falls back the same way: a hand-edited budget
		// never makes a live job unreadable, it just starts the next stall from the
		// configured size.
		$budget_keys_present = array_key_exists( 'chunk_size', $data )
			|| array_key_exists( 'table_chunk_bytes', $data )
			|| array_key_exists( 'table_chunk_rows', $data );
		$chunk_size = ( isset( $data['chunk_size'] ) && is_int( $data['chunk_size'] ) && $data['chunk_size'] >= 0 ) ? $data['chunk_size'] : 0;
		$table_chunk_bytes = ( isset( $data['table_chunk_bytes'] ) && is_int( $data['table_chunk_bytes'] ) && $data['table_chunk_bytes'] >= 0 ) ? $data['table_chunk_bytes'] : 0;
		$table_chunk_rows = ( isset( $data['table_chunk_rows'] ) && is_int( $data['table_chunk_rows'] ) && $data['table_chunk_rows'] >= 0 ) ? $data['table_chunk_rows'] : 0;

		// The first-tick limit pair is a later addition to unreleased schema 8; an
		// older write carries none of them, so an absent or ill-typed value reads
		// as not-yet-measured rather than disqualifying the record.
		$host_memory_limit = is_string( $data['host_memory_limit'] ?? null ) ? $data['host_memory_limit'] : null;
		$host_max_execution_time = is_string( $data['host_max_execution_time'] ?? null ) ? $data['host_max_execution_time'] : null;
		$raised_memory_limit = is_string( $data['raised_memory_limit'] ?? null ) ? $data['raised_memory_limit'] : null;
		$raised_max_execution_time = is_string( $data['raised_max_execution_time'] ?? null ) ? $data['raised_max_execution_time'] : null;

		// Skipped files are a later addition to unreleased schema 8; an older write
		// carries none, so an absent or ill-typed value reads as nothing skipped
		// rather than disqualifying the record.
		$skipped_files = array_key_exists( 'skipped_files', $data ) ? self::string_list( $data['skipped_files'] ) : [];
		if ( $skipped_files === null ) {
			$skipped_files = [];
		}

		// The attempt log is a later addition to unreleased schema 8; an older write
		// carries none, so an absent or ill-typed value reads as nothing begun rather
		// than disqualifying the record. A malformed entry is dropped, not fatal.
		$attempt_log = [];
		if ( isset( $data['attempt_log'] ) && is_array( $data['attempt_log'] ) && array_is_list( $data['attempt_log'] ) ) {
			foreach ( $data['attempt_log'] as $entry ) {
				$attempt = Chunk_Attempt::from_array( $entry );
				if ( $attempt !== null ) {
					$attempt_log[] = $attempt;
				}
			}
			if ( count( $attempt_log ) > self::ATTEMPT_LOG_BOUND ) {
				$attempt_log = array_slice( $attempt_log, -self::ATTEMPT_LOG_BOUND );
			}
		}

		// Reject the record unless every field is present and correctly typed; a
		// pre-execution record without the tick secret or artifact name is a schema
		// this release cannot drive, so it reads as no readable job here.
		if ( $state === null
			|| ! is_string( $id ) || $id === ''
			|| ! is_int( $owner )
			|| ! is_string( $public_key )
			|| $tables === null
			|| $structure_only === null
			|| $files === null
			|| ! is_int( $created_at )
			|| ! is_int( $updated_at )
			|| ! is_string( $tick_secret ) || $tick_secret === ''
			|| ! is_string( $artifact ) || $artifact === '' ) {
			return null;
		}

		return new self( $id, $state, $owner, $public_key, $tables, $structure_only, $files, $created_at, $updated_at, $tick_secret, $artifact, $progress, $progressed_at, $attempts, $error, $chunk_size, $table_chunk_bytes, $table_chunk_rows, $budget_keys_present, $host_memory_limit, $host_max_execution_time, $raised_memory_limit, $raised_max_execution_time, $skipped_files, $attempt_log );

	}

	/**
	 * Coerces a decoded value into a list of strings, or null when it is not one.
	 *
	 * A selection must be a plain array whose every element is a string; a scalar,
	 * a map, or a mixed-type array is not a valid selection and yields null.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value A decoded `tables` or `files` value.
	 * @return array<int, string>|null The value as a list of strings, or null.
	 */
	private static function string_list( mixed $value ): ?array {

		// Only a list-shaped array of strings qualifies; anything else disqualifies
		// the selection and, through the caller, the whole record.
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}
		$strings = [];
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return null;
			}
			$strings[] = $item;
		}

		return $strings;

	}

}
