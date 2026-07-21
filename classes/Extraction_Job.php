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
	 * field set or their meaning changes.
	 *
	 * @since 0.1.0
	 */
	public const int SCHEMA_VERSION = 1;

	/**
	 * Builds a job record from its fully-resolved fields.
	 *
	 * The caller supplies already-validated values: the id is the unguessable
	 * directory name, the selections have been checked for existence, and the key
	 * is canonical base64 of a 32-byte X25519 public key.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $id         Unguessable job identifier; also its directory name.
	 * @param Job_State          $state      Lifecycle state the job is in.
	 * @param int                $owner      WordPress user id the job is bound to.
	 * @param string             $public_key Caller's ephemeral X25519 public key, as base64.
	 * @param array<int, string> $tables     Requested table names, already resolved to existing tables.
	 * @param array<int, string> $files      Requested file paths, already resolved inside the root.
	 * @param int                $created_at Unix timestamp the job was created at.
	 * @param int                $updated_at Unix timestamp the job last changed state at.
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
	) {}

	/**
	 * Serialises the job into the associative array persisted as JSON.
	 *
	 * The state crosses the boundary as its backed string value, and the schema
	 * version is stamped in so a reader can tell which shape it is looking at.
	 *
	 * @since 0.1.0
	 *
	 * @return array{version: int, id: string, state: string, owner: int, public_key: string, tables: array<int, string>, files: array<int, string>, created_at: int, updated_at: int}
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

		// Reject the record unless every field is present and correctly typed.
		if ( $state === null
			|| ! is_string( $id ) || $id === ''
			|| ! is_int( $owner )
			|| ! is_string( $public_key )
			|| $tables === null
			|| $files === null
			|| ! is_int( $created_at )
			|| ! is_int( $updated_at ) ) {
			return null;
		}

		return new self( $id, $state, $owner, $public_key, $tables, $files, $created_at, $updated_at );

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
