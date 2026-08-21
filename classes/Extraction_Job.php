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
	 * a later stall reason reads, the `skipped_files` a vanished file is recorded
	 * under, and the bounded `attempt_log` a tick records so a poll can answer what
	 * was begun (ADR-0016), so none of those needs a bump. Nor does the `thrown`
	 * reason an unexpected throw records (issue #25), added to the same version after
	 * 0.6.0 shipped and read the same tolerant way: an absent key is a record that has
	 * not failed that way, and a release too old to know the key ignores it. Nor does
	 * the `strict` flag the packaging path reads (issue #31, ADR-0026), which is that
	 * shape again — written only when false, absent meaning the hard fail on a vanished
	 * file that every earlier record already had. Nor does the `timing_log` a chunk
	 * records when the `phase_timing` knob asks for it (issue #39), which joins on
	 * exactly that footing: absent on every record the knob was off for, which is every
	 * record by default. ADR-0024 settles the general case behind all four: there is no
	 * earlier record to stay compatible with, so what a bump would signal has no reader.
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
	 * Newest completed chunks the job record keeps a phase timing for.
	 *
	 * The same last-N shape as {@see ATTEMPT_LOG_BOUND}, for the same reason: a
	 * debug series that grew with the selection would put the half-terabyte write
	 * problem back (ADR-0014), and the question this one answers — where does a
	 * chunk's time go — is answered by a sample rather than a census. Eight chunks
	 * per poll over a run of tens of thousands is a far larger series than the
	 * question needs, and it keeps `state.json` a few hundred bytes.
	 *
	 * A separate constant from the attempt bound although the number is the same:
	 * the two surfaces are bounded for the same reason but are not the same surface,
	 * and tying one to the other would make a change to either read as a change to
	 * both.
	 */
	public const int TIMING_LOG_BOUND = 8;

	/**
	 * The record's keys that are persisted apart from the rest, in the selection file.
	 *
	 * These are the fields whose size is unbounded — a selection runs to tens of
	 * thousands of paths. Splitting exactly them out is what lets a save rewrite a few
	 * hundred bytes instead of megabytes, without any field changing its name or
	 * meaning (ADR-0014). The split is a persistence concern, so {@see Job_Store}
	 * performs it; the line is drawn here because which fields are unbounded is schema
	 * knowledge.
	 *
	 * The line is unboundedness and not immutability, which used to amount to the same
	 * thing and no longer does: `skipped_files` is appended to mid-run by a tick that
	 * passed over a vanished file (ADR-0026). That is the one lifecycle change reaching
	 * this half, and {@see Job_Store::save_with_selection()} is the only write that
	 * carries it — the ordinary save still rewrites the state file alone.
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
	 * @param string|null               $error       Why the job failed, when the plugin diagnosed the failure itself, or null. Composed entirely from the caller's own selection and settings `GET /environment` already discloses, so this one carries no filesystem path, SQL, or other internal detail (ADR-0022). An unexpected throw is not a diagnosis of the plugin's own and is carried by $thrown instead, because what the two may disclose differs (ADR-0022).
	 * @param int                       $chunk_size  Per-job file-part budget in bytes, or 0 to use the Config default. Set at create when the caller asked for one on `POST /extractions` (issue #28), and overwritten when a stall halves it (ADR-0015) so the next tick packages a smaller part rather than walking back into the same wall. The two are the same field on purpose: a requested size is a starting point the adaptation is free to walk down from, not a floor under it.
	 * @param int                       $table_chunk_bytes Per-job table-slice byte budget, or 0 to use the Config default. The table-side counterpart of $chunk_size.
	 * @param int                       $table_chunk_rows Per-job upper bound on rows fetched for one table slice, or 0 to use the Config default. Halved alongside $table_chunk_bytes, because the byte budget bounds only what is rendered while this bounds what `$wpdb->get_results()` materialises — the half a table stall is most likely to have died in (ADR-0015).
	 * @param string|null               $host_memory_limit The host's `memory_limit` before the first tick asked for more, or null until that tick has run. The stall reason reads this rather than the live process, so a later tick still names the pair in force when the chunk died.
	 * @param string|null               $host_max_execution_time The host's `max_execution_time` before the first tick asked for more, or null until that tick has run.
	 * @param string|null               $raised_memory_limit The `memory_limit` in force after the first tick's ask, or null until that tick has run.
	 * @param string|null               $raised_max_execution_time The `max_execution_time` in force after the first tick's ask, or null until that tick has run.
	 * @param array<int, string>        $skipped_files Install-root-relative paths dropped from the artifact because they no longer existed — at create, or at the moment the packaging path reached one under `strict: false` (ADR-0026). Empty when nothing was skipped. Appended to mid-run, so it is the one selection-half field a tick can change.
	 * @param array<int, Chunk_Attempt> $attempt_log Newest begun chunk attempts, oldest first, capped at {@see ATTEMPT_LOG_BOUND}. Empty until the first tick. A debug surface, not the stall counter (ADR-0016).
	 * @param string|null               $thrown Why the job failed, when an unexpected throw killed the build, or null. The counterpart of $error and deliberately not the same field: this one is composed around a string the plugin did not write and cannot vouch for, whereas $error is its own diagnosis (issue #25). Surfaced verbatim to the polling owner like $error, but under a deliberately wider rule: the relayed part **may** carry a filesystem path or a fragment of SQL, and the bound on it limits the size of that disclosure rather than its kind (ADR-0022).
	 * @param bool                      $strict Whether a vanished file is fatal to this job. False is what the caller asked for on the create, and it is carried here rather than left at the door because the create filter closes only the gap up to the POST while the packaging path meets the much larger one after it (ADR-0026). True is the default in every direction: an omitted member, and an absent or ill-typed key on a record written before this field existed, all read as the hard fail that has always been the behaviour.
	 * @param array<int, Chunk_Timing>  $timing_log Newest completed chunks' phase timings, oldest first, capped at {@see TIMING_LOG_BOUND}. Empty unless the `phase_timing` knob asked for the measurement, which it does not by default (issue #39). A debug surface like the attempt log beside it, and recorded on the opposite side of the work: an attempt is written before a chunk so a host kill leaves evidence, a timing only once the chunk it measures has finished.
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
		public ?string $host_memory_limit = null,
		public ?string $host_max_execution_time = null,
		public ?string $raised_memory_limit = null,
		public ?string $raised_max_execution_time = null,
		public array $skipped_files = [],
		public array $attempt_log = [],
		public ?string $thrown = null,
		public bool $strict = true,
		public array $timing_log = [],
	) {}

	/**
	 * Returns a copy carrying one more skipped file, or this one when it already does.
	 *
	 * The packaging path reaches this when a `strict: false` job meets a file that has
	 * gone since the job was created (ADR-0026). Appending is idempotent because the
	 * skip is persisted before the progress that moves past the file: a tick killed in
	 * that window leaves the next one re-reaching the same file, and re-recording it
	 * must not lengthen the list the caller reads.
	 *
	 * Unlike every other mutation here this one touches the selection half of the
	 * record, so the driver persists it through {@see Job_Store::save_with_selection()}
	 * rather than the ordinary save.
	 *
	 * @param string $file The installation-root-relative path that was skipped.
	 * @return self A new record naming the skipped file, or this one when it already did.
	 */
	public function with_skipped_file( string $file ): self {

		// Nothing to record when the path is already on the list; see the idempotence
		// note above for why that case is reachable rather than defensive.
		if ( in_array( $file, $this->skipped_files, true ) ) {
			return $this;
		}

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts, $this->error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, [ ...$this->skipped_files, $file ], $this->attempt_log, $this->thrown, $this->strict, $this->timing_log );

	}

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

		return new self( $this->id, $state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts, $this->error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log, $this->thrown, $this->strict, $this->timing_log );

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

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts + 1, $this->error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $log, $this->thrown, $this->strict, $this->timing_log );

	}

	/**
	 * Returns a copy carrying one more chunk's phase timing, stamped as just updated.
	 *
	 * The mirror image of {@see with_attempt()}, and deliberately on the other side of
	 * the work: an attempt is recorded before a chunk runs so a kill outside PHP still
	 * leaves evidence, while a timing is only meaningful once the chunk it measures has
	 * finished, so a killed chunk records none. Reached only when the `phase_timing`
	 * knob asked for the measurement (issue #39); a job that did not ask never calls
	 * this and its record keeps the shape it has always had.
	 *
	 * The series is last-N for the reason the attempt log is (ADR-0014/0016): a debug
	 * surface must not grow with the selection.
	 *
	 * @param Chunk_Timing $timing What the chunk just completed spent, phase by phase.
	 * @return self A new record identical to this one but carrying the timing.
	 */
	public function with_timing( Chunk_Timing $timing ): self {

		// Append this chunk onto the last-N series, dropping the oldest once full.
		$series = [ ...$this->timing_log, $timing ];
		if ( count( $series ) > self::TIMING_LOG_BOUND ) {
			$series = array_slice( $series, -self::TIMING_LOG_BOUND );
		}

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts, $this->error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log, $this->thrown, $this->strict, $series );

	}

	/**
	 * Returns a copy failed with a reason the plugin diagnosed itself, stamped as just updated.
	 *
	 * Reserved for a failure the plugin worked out on its own and can describe entirely
	 * in the caller's own vocabulary — the stalled build (ADR-0013), which composes its
	 * reason from the selection the caller submitted and two settings `GET /environment`
	 * already discloses. That is what {@see $error} means, and it is why a failure the
	 * plugin did not diagnose but merely caught goes through {@see with_thrown_failure()}
	 * instead — the same transition into `failed`, with the reason routed to a field of
	 * its own because what the two may disclose differs (ADR-0022, issue #25). What
	 * leaves both fields null is a failure no `catch` ever reaches — a process killed
	 * outright — and after those two that absence is itself the diagnosis rather than a
	 * gap.
	 *
	 * @since 0.4.0
	 *
	 * @param string $error Why the job failed, phrased for the polling owner to act on.
	 * @return self A new record identical to this one but failed and carrying the reason.
	 */
	public function with_failure( string $error ): self {

		return new self( $this->id, Job_State::Failed, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts, $error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log, $this->thrown, $this->strict, $this->timing_log );

	}

	/**
	 * Returns a copy failed by an unexpected throw, carrying what threw, stamped as just updated.
	 *
	 * The other way a build ends, and deliberately not {@see with_failure()}. The reason
	 * lands in {@see $thrown} and {@see $error} is left null, because the two fields are
	 * kept apart by what each may disclose rather than by how they read: {@see $error}
	 * carries nothing the plugin did not write, while this one relays a string the
	 * plugin caught and cannot vouch for (ADR-0022, issue #25).
	 *
	 * The poll is unaffected either way: {@see Rest\Extractions_Controller::error_of()}
	 * resolves the plugin's own diagnosis first, then this one, then its fallback
	 * sentence, so `error.message` still carries exactly one string.
	 *
	 * @since 0.7.0
	 *
	 * @param string $thrown Why the job failed, composed around the throwable that killed it.
	 * @return self A new record identical to this one but failed and carrying the thrown reason.
	 */
	public function with_thrown_failure( string $thrown ): self {

		return new self( $this->id, Job_State::Failed, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts, null, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log, $thrown, $this->strict, $this->timing_log );

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

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $progress, time(), 0, $this->error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log, $this->thrown, $this->strict, $this->timing_log );

	}

	/**
	 * Returns a copy carrying adapted packaging budgets and a cleared attempt counter.
	 *
	 * The stall path (ADR-0015) calls this instead of failing the job when a chunk has
	 * died at the same offset enough times and the relevant budget can still shrink.
	 * Attempts reset so the new size gets a fresh window; the budgets persist so a
	 * later tick — or a resume after the job has left `running` — packages the smaller
	 * part rather than rediscovering the same wall.
	 *
	 * @since 0.6.0
	 *
	 * @param Chunk_Budgets $budgets The adapted bounds to persist.
	 * @return self A new record identical to this one but with the budgets and no attempts.
	 */
	public function with_budgets( Chunk_Budgets $budgets ): self {

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, 0, $this->error, $budgets->file_bytes, $budgets->table_bytes, $budgets->table_rows, $this->host_memory_limit, $this->host_max_execution_time, $this->raised_memory_limit, $this->raised_max_execution_time, $this->skipped_files, $this->attempt_log, $this->thrown, $this->strict, $this->timing_log );

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

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at, $this->attempts, $this->error, $this->chunk_size, $this->table_chunk_bytes, $this->table_chunk_rows, $host_memory_limit, $host_max_execution_time, $raised_memory_limit, $raised_max_execution_time, $this->skipped_files, $this->attempt_log, $this->thrown, $this->strict, $this->timing_log );

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
	 * @return array{version: int, id: string, state: string, owner: int, public_key: string, tables: array<int, string>, structure_only: array<int, string>, files: array<int, string>, skipped_files?: array<int, string>, created_at: int, updated_at: int, tick_secret: string, artifact: string, progress: array{tables_done: int, structure_done: int, file_index: int, file_offset: int, container_bytes: int, index_bytes: int, segment_count: int, file_size: int|null, file_mtime: int|null, table_offset: int, table_cursor: array<int, string>|null}|null, progressed_at: int|null, attempts: int, error: string|null, chunk_size: int, table_chunk_bytes: int, table_chunk_rows: int, strict?: false, thrown?: string, host_memory_limit?: string, host_max_execution_time?: string, raised_memory_limit?: string, raised_max_execution_time?: string, attempt_log?: list<array{at: int, kind: string, n: int, offset: int}>, timing_log?: list<array{at: int, phases: array<string, int>}>}
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
			'chunk_size' => $this->chunk_size,
			'table_chunk_bytes' => $this->table_chunk_bytes,
			'table_chunk_rows' => $this->table_chunk_rows,
		];

		// The thrown reason is written only once a throw has recorded one. Its absence
		// is the ordinary shape of every record that has not failed that way, which is
		// why it is omitted rather than persisted as an explicit null (issue #25).
		if ( $this->thrown !== null ) {
			$record['thrown'] = $this->thrown;
		}

		// The first-tick limit pair is written only once it has been measured; a job
		// that has not been ticked yet keeps the keys absent rather than inventing
		// empty readings.
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

		// Omit the skipped-file list when nothing was skipped, so a job that never used
		// strict: false carries no key for it at all.
		if ( $this->skipped_files !== [] ) {
			$record['skipped_files'] = $this->skipped_files;
		}

		// The strict flag is written only when it is false, which is the same
		// omit-the-default shape every optional key here uses. It reaches the record at
		// all because the packaging path needs it, not merely the create (ADR-0026).
		if ( ! $this->strict ) {
			$record['strict'] = false;
		}

		// Omit the attempt log until something has been begun, so a job that has not
		// been ticked carries no key for it at all (ADR-0016).
		if ( $this->attempt_log !== [] ) {
			$serialized = [];
			foreach ( $this->attempt_log as $attempt ) {
				$serialized[] = $attempt->to_array();
			}
			$record['attempt_log'] = $serialized;
		}

		// Omit the timing series unless the measurement was asked for and a chunk has
		// completed under it, so the default record is byte for byte what it was before
		// the instrument existed (issue #39).
		if ( $this->timing_log !== [] ) {
			$timings = [];
			foreach ( $this->timing_log as $timing ) {
				$timings[] = $timing->to_array();
			}
			$record['timing_log'] = $timings;
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

		// The structure-only selection (issue #16) is written by every release this one
		// understands, so an absent value is not an empty selection but a record from a
		// release this one does not read (ADR-0024), and yields null like a malformed
		// one does.
		$structure_only = self::string_list( $data['structure_only'] ?? null );
		$created_at = $data['created_at'] ?? null;
		$updated_at = $data['updated_at'] ?? null;
		$tick_secret = $data['tick_secret'] ?? null;
		$artifact = $data['artifact'] ?? null;

		// Build-progress is written as an explicit null until the build begins, so a
		// null — or a key an unfinished create never got to — reads as "not begun"
		// rather than disqualifying the record.
		$progress = array_key_exists( 'progress', $data ) && $data['progress'] !== null ? Build_Progress::from_array( $data['progress'] ) : null;

		// The last-progress timestamp is null until a chunk has advanced, and the sweep
		// treats that null as the creation time. A present-but-ill-typed value is read
		// as absent rather than disqualifying the whole record, so a hand-edited stamp
		// never makes a live job unreadable — the ceiling simply falls back to the
		// creation time for it.
		$progressed_at = ( isset( $data['progressed_at'] ) && is_int( $data['progressed_at'] ) && $data['progressed_at'] >= 0 ) ? $data['progressed_at'] : null;

		// The attempt counter and the diagnosed failure reason (ADR-0013) are on every
		// record this release understands — the reason as an explicit null until one is
		// written — so an absent KEY disqualifies the record (ADR-0024). A present but
		// ill-typed VALUE still falls back to its default: a hand-edited counter costs
		// one more attempt before the stall is called and can corrupt no artifact, so it
		// must not make a live job unreadable.
		$has_stall_fields = array_key_exists( 'attempts', $data ) && array_key_exists( 'error', $data );
		$attempts = ( isset( $data['attempts'] ) && is_int( $data['attempts'] ) && $data['attempts'] >= 0 ) ? $data['attempts'] : 0;
		$error = is_string( $data['error'] ?? null ) ? $data['error'] : null;

		// The thrown reason is written only once a throw has recorded one (issue #25), so
		// an absent or ill-typed value reads as nothing thrown — the ordinary shape of a
		// record that has not failed that way, and never a statement about the release
		// that wrote it.
		$thrown = is_string( $data['thrown'] ?? null ) ? $data['thrown'] : null;

		// The per-job packaging budgets (ADR-0015) are stamped by every release this one
		// understands, as zero while the job still packages at the Config defaults, so an
		// absent key disqualifies the record (ADR-0024). An ill-typed value falls back to
		// zero for the same reason the counter above does: it starts the next stall from
		// the configured size and makes nothing unreadable.
		$has_budget_keys = array_key_exists( 'chunk_size', $data )
			&& array_key_exists( 'table_chunk_bytes', $data )
			&& array_key_exists( 'table_chunk_rows', $data );
		$chunk_size = ( isset( $data['chunk_size'] ) && is_int( $data['chunk_size'] ) && $data['chunk_size'] >= 0 ) ? $data['chunk_size'] : 0;
		$table_chunk_bytes = ( isset( $data['table_chunk_bytes'] ) && is_int( $data['table_chunk_bytes'] ) && $data['table_chunk_bytes'] >= 0 ) ? $data['table_chunk_bytes'] : 0;
		$table_chunk_rows = ( isset( $data['table_chunk_rows'] ) && is_int( $data['table_chunk_rows'] ) && $data['table_chunk_rows'] >= 0 ) ? $data['table_chunk_rows'] : 0;

		// The first-tick limit pair is written only once a tick has measured it, so an
		// absent or ill-typed value reads as not-yet-measured rather than disqualifying
		// the record.
		$host_memory_limit = is_string( $data['host_memory_limit'] ?? null ) ? $data['host_memory_limit'] : null;
		$host_max_execution_time = is_string( $data['host_max_execution_time'] ?? null ) ? $data['host_max_execution_time'] : null;
		$raised_memory_limit = is_string( $data['raised_memory_limit'] ?? null ) ? $data['raised_memory_limit'] : null;
		$raised_max_execution_time = is_string( $data['raised_max_execution_time'] ?? null ) ? $data['raised_max_execution_time'] : null;

		// The skipped-file list is omitted when nothing was skipped, so an absent or
		// ill-typed value reads as exactly that rather than disqualifying the record.
		$skipped_files = array_key_exists( 'skipped_files', $data ) ? self::string_list( $data['skipped_files'] ) : [];
		if ( $skipped_files === null ) {
			$skipped_files = [];
		}

		// The strict flag is written only when it is false, so anything else — absent,
		// ill-typed, or explicitly true — reads as the hard fail on a vanished file that
		// every record written before this field existed already had (ADR-0026).
		$strict = ( $data['strict'] ?? null ) !== false;

		// The attempt log is omitted until a tick has begun something, so an absent or
		// ill-typed value reads as nothing begun rather than disqualifying the record.
		// A malformed entry is dropped, not fatal.
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

		// The timing series is omitted unless the measurement was asked for, which it is
		// not by default, so an absent or ill-typed value reads as nothing measured
		// rather than disqualifying the record. A malformed entry is dropped, not fatal.
		$timing_log = [];
		if ( isset( $data['timing_log'] ) && is_array( $data['timing_log'] ) && array_is_list( $data['timing_log'] ) ) {
			foreach ( $data['timing_log'] as $entry ) {
				$timing = Chunk_Timing::from_array( $entry );
				if ( $timing !== null ) {
					$timing_log[] = $timing;
				}
			}
			if ( count( $timing_log ) > self::TIMING_LOG_BOUND ) {
				$timing_log = array_slice( $timing_log, -self::TIMING_LOG_BOUND );
			}
		}

		// Reject the record unless every field this release writes is present and
		// correctly typed. That includes the keys an older release never wrote: this
		// release understands the shapes it writes and no others (ADR-0024), so such a
		// record reads as no readable job here and its enumeration simply skips it.
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
			|| ! is_string( $artifact ) || $artifact === ''
			|| ! $has_stall_fields
			|| ! $has_budget_keys ) {
			return null;
		}

		return new self( $id, $state, $owner, $public_key, $tables, $structure_only, $files, $created_at, $updated_at, $tick_secret, $artifact, $progress, $progressed_at, $attempts, $error, $chunk_size, $table_chunk_bytes, $table_chunk_rows, $host_memory_limit, $host_max_execution_time, $raised_memory_limit, $raised_max_execution_time, $skipped_files, $attempt_log, $thrown, $strict, $timing_log );

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
