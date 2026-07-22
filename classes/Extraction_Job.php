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
	 * chunked, resumable build added durable build-progress (ADR-0007), and to 4
	 * when the last-progress timestamp made the sweep's absolute lifetime ceiling
	 * measure stalled progress rather than raw age.
	 *
	 * @since 0.1.0
	 */
	public const int SCHEMA_VERSION = 4;

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
	 * @param string              $id          Unguessable job identifier; also its directory name.
	 * @param Job_State           $state       Lifecycle state the job is in.
	 * @param int                 $owner       WordPress user id the job is bound to.
	 * @param string              $public_key  Caller's ephemeral X25519 public key, as base64.
	 * @param array<int, string>  $tables      Requested table names, already resolved to existing tables.
	 * @param array<int, string>  $files       Requested file paths, already resolved inside the root.
	 * @param int                 $created_at  Unix timestamp the job was created at.
	 * @param int                 $updated_at  Unix timestamp the job last changed state at.
	 * @param string              $tick_secret Per-job secret authenticating the internal tick endpoint.
	 * @param string              $artifact    Unguessable filename of the sealed artifact in the job directory.
	 * @param Build_Progress|null $progress   How far the chunked build has got, or null before it begins.
	 * @param int|null            $progressed_at Unix timestamp the build last advanced a chunk, or null before it has (treated as the creation time). Distinct from $updated_at, which every state save refreshes: this moves only on real progress, so the sweep's absolute ceiling can tell a slow-but-advancing large job from one whose chunk fails uncatchably every attempt.
	 */
	public function __construct(
		public string $id,
		public Job_State $state,
		public int $owner,
		public string $public_key,
		public array $tables,
		public array $files,
		public int $created_at,
		public int $updated_at,
		public string $tick_secret,
		public string $artifact,
		public ?Build_Progress $progress = null,
		public ?int $progressed_at = null,
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

		return new self( $this->id, $state, $this->owner, $this->public_key, $this->tables, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $this->progress, $this->progressed_at );

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
	 * @since 0.1.0
	 *
	 * @param Build_Progress $progress The progress the latest chunk reached.
	 * @return self A new record identical to this one but carrying the progress.
	 */
	public function with_progress( Build_Progress $progress ): self {

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $progress, time() );

	}

	/**
	 * Serialises the job into the associative array persisted as JSON.
	 *
	 * The state crosses the boundary as its backed string value, and the schema
	 * version is stamped in so a reader can tell which shape it is looking at.
	 *
	 * @since 0.1.0
	 *
	 * @return array{version: int, id: string, state: string, owner: int, public_key: string, tables: array<int, string>, files: array<int, string>, created_at: int, updated_at: int, tick_secret: string, artifact: string, progress: array{tables_done: int, file_index: int, file_offset: int, container_bytes: int, segment_names: array<int, string>, file_size: int|null, file_mtime: int|null}|null, progressed_at: int|null}
	 */
	public function to_array(): array {

		return [
			'version' => self::SCHEMA_VERSION,
			'id' => $this->id,
			'state' => $this->state->value,
			'owner' => $this->owner,
			'public_key' => $this->public_key,
			'tables' => $this->tables,
			'files' => $this->files,
			'created_at' => $this->created_at,
			'updated_at' => $this->updated_at,
			'tick_secret' => $this->tick_secret,
			'artifact' => $this->artifact,
			'progress' => $this->progress?->to_array(),
			'progressed_at' => $this->progressed_at,
		];

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

		// Reject the record unless every field is present and correctly typed; a
		// pre-execution record without the tick secret or artifact name is a schema
		// this release cannot drive, so it reads as no readable job here.
		if ( $state === null
			|| ! is_string( $id ) || $id === ''
			|| ! is_int( $owner )
			|| ! is_string( $public_key )
			|| $tables === null
			|| $files === null
			|| ! is_int( $created_at )
			|| ! is_int( $updated_at )
			|| ! is_string( $tick_secret ) || $tick_secret === ''
			|| ! is_string( $artifact ) || $artifact === '' ) {
			return null;
		}

		return new self( $id, $state, $owner, $public_key, $tables, $files, $created_at, $updated_at, $tick_secret, $artifact, $progress, $progressed_at );

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
