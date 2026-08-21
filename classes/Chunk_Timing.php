<?php
/**
 * One timed packaging chunk, as persisted on the job's timing series.
 *
 * @package Kntnt\Extractor
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * A single last-N entry of the bounded phase-timing series (issue #39).
 *
 * Recorded when a chunk has completed, which is what separates this from the
 * attempt log beside it: an attempt is written before the work so a host kill
 * leaves evidence (ADR-0016), while a timing is only meaningful once the work it
 * measures has finished. The persisted shape is a stamp and a map of phase name to
 * integer microseconds, so the state file stays path-free (ADR-0014), carries no
 * secret, and names nothing the caller did not select.
 *
 * It is a debug surface and not part of the artifact contract, so it does not move
 * `API_VERSION`; a client learns whether a build carries it from the `honours` list
 * instead (ADR-0017).
 */
final readonly class Chunk_Timing {

	/**
	 * Captures what one completed chunk spent, phase by phase.
	 *
	 * @param int                $at     Unix timestamp the chunk finished at.
	 * @param array<string, int> $phases Microseconds spent, keyed by {@see Phase_Timer}'s phase names.
	 */
	public function __construct(
		public int $at,
		public array $phases,
	) {}

	/**
	 * Serialises the timing into the two members persisted on the state file.
	 *
	 * @return array{at: int, phases: array<string, int>}
	 */
	public function to_array(): array {

		return [
			'at' => $this->at,
			'phases' => $this->phases,
		];

	}

	/**
	 * Reconstructs a timing from a decoded series entry, or null when it is not one.
	 *
	 * @param mixed $data A decoded `timing_log` element.
	 * @return self|null The reconstructed timing, or null when the entry is unusable.
	 */
	public static function from_array( mixed $data ): ?self {

		if ( ! is_array( $data ) ) {
			return null;
		}

		// Narrow the stamp; an ill-typed one drops the entry rather than the whole job.
		$at = $data['at'] ?? null;
		$raw = $data['phases'] ?? null;
		if ( ! is_int( $at ) || $at < 0 || ! is_array( $raw ) ) {
			return null;
		}

		// Keep only name-to-duration pairs; a hand-edited or truncated entry loses the
		// pairs it broke rather than the series it sits in.
		$phases = [];
		foreach ( $raw as $name => $microseconds ) {
			if ( is_string( $name ) && is_int( $microseconds ) && $microseconds >= 0 ) {
				$phases[ $name ] = $microseconds;
			}
		}

		return new self( $at, $phases );

	}

}
