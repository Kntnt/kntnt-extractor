<?php
/**
 * Wall-clock instrument for the phases one packaging chunk spends its time in.
 *
 * @package Kntnt\Extractor
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * Times the phases of one packaging chunk, so a per-chunk cost can be attributed.
 *
 * An ordinary small file costs roughly 229 ms per chunk on the measured production
 * host (`docs/measurements/2026-08-19-successful-run.md`), and across ~48,500 files
 * that is very nearly the whole run. Nothing has ever attributed it, because the
 * build could not see inside a tick: the leading hypothesis is filesystem latency,
 * and a chunk makes 15–25 filesystem calls, but a single per-chunk total can neither
 * confirm that nor kill it. This instrument separates the phases those calls fall
 * into — the container's open or resume, the path resolution and its stats, the
 * source read, the seal, the container's suspend, and the record-split save — so a
 * production series says where the time goes rather than bounding it (issue #39).
 *
 * It is created only when the `phase_timing` knob asks for it, so a production run
 * that did not ask for measurement constructs nothing and reads no clock at all;
 * every call site marks its phase through a null-safe call on a null timer.
 *
 * **The measurement does not distort what it measures.** A mark is one `hrtime()`
 * call and one array write. `hrtime()` is a monotonic clock read — on every platform
 * this plugin runs on a vDSO read costing tens of nanoseconds, never a syscall — and
 * a chunk marks at most eight phases, so sixteen marks cost single-digit microseconds
 * against the ~229 ms being attributed: below one part in ten thousand, and far below
 * the spread between two consecutive chunks. The monotonic clock is also what makes
 * the numbers trustworthy over a multi-hour run, where `microtime()` would let an NTP
 * step land inside a measured phase.
 */
final class Phase_Timer {

	/**
	 * The whole chunk, from the driver's first act to the record that carries it.
	 *
	 * The denominator every other phase is read against: what it does not account
	 * for is the unattributed remainder, which is the number this instrument exists
	 * to shrink. It excludes only the save that persists the entry itself, which
	 * cannot time its own write — see {@see SAVE}.
	 */
	public const string TOTAL = 'total';

	/**
	 * The record-split save of the job's state file (ADR-0014).
	 *
	 * A chunk pays this twice: once before the work, counting the attempt while a
	 * record of it can still be written, and once after, persisting the progress —
	 * and the second of those is the write that carries this very entry, so it can
	 * only be measured from outside itself. What is recorded here is the first save,
	 * and a reader attributing a whole chunk doubles it.
	 *
	 * The doubling holds only where both writes are {@see Job_Store::save()}. On a
	 * chunk that skipped a vanished file, the driver routes the second one to
	 * {@see Job_Store::save_with_selection()}, which rewrites the unbounded selection
	 * half as well — a full record write, paid per *skip* and deliberately unlike the
	 * bounded state-file save recorded here (ADR-0026). On such a chunk the doubled
	 * figure understates what was spent.
	 */
	public const string SAVE = 'save';

	/**
	 * Opening a fresh container and writing its header, on a build's first chunk.
	 */
	public const string OPEN = 'open';

	/**
	 * Reopening the in-progress container and its sidecar at the committed offsets.
	 *
	 * Separate from {@see OPEN} because they are different work on different files:
	 * this is two opens and two `ftruncate`s on a container that grows past a
	 * gigabyte, and it is paid by every chunk after the first.
	 */
	public const string RESUME = 'resume';

	/**
	 * Resolving a selected path inside the installation root, and its `stat` calls.
	 *
	 * A chunk can resolve the same path twice: a `strict: false` job first asks
	 * whether the file is still there at all, so that a vanished one is a skip rather
	 * than a failure (ADR-0026), and the part read then resolves it again for the
	 * bytes. Both are `realpath` walks against the same root, so both accumulate here
	 * rather than one of them falling into the unattributed remainder.
	 */
	public const string RESOLVE = 'resolve';

	/**
	 * The source file's open-seek-read-close, for one bounded part.
	 */
	public const string READ = 'read';

	/**
	 * Sealing one segment and appending it to the container and its index.
	 */
	public const string SEAL = 'seal';

	/**
	 * Flushing, measuring and closing the container and its sidecar mid-build.
	 */
	public const string SUSPEND = 'suspend';

	/**
	 * Nanoseconds per microsecond, the unit `hrtime()` is reduced to for the record.
	 *
	 * Microseconds are three orders of magnitude finer than the millisecond the
	 * question is asked in, and they stay integers across JSON — which a float
	 * duration would not, and which is what keeps a persisted series comparable.
	 */
	private const int NANOSECONDS_PER_MICROSECOND = 1000;

	/**
	 * The monotonic reading each currently-open phase was started at.
	 *
	 * @var array<string, int>
	 */
	private array $started = [];

	/**
	 * Microseconds accumulated per phase so far in this chunk.
	 *
	 * @var array<string, int>
	 */
	private array $elapsed = [];

	/**
	 * Marks the beginning of a phase.
	 *
	 * @param string $phase One of this class's phase names.
	 * @return void
	 */
	public function start( string $phase ): void {

		$this->started[ $phase ] = (int) hrtime( true );

	}

	/**
	 * Marks the end of a phase, adding its duration to what the chunk has spent there.
	 *
	 * Accumulating rather than assigning is what lets a phase that runs more than
	 * once in a chunk — a seal, on a branch that adds several segments — report the
	 * chunk's whole cost in it rather than the last occurrence's.
	 *
	 * @param string $phase The phase name a matching {@see start()} opened.
	 * @return void
	 */
	public function stop( string $phase ): void {

		$this->elapsed[ $phase ] = ( $this->elapsed[ $phase ] ?? 0 ) + intdiv( (int) hrtime( true ) - $this->started[ $phase ], self::NANOSECONDS_PER_MICROSECOND );

	}

	/**
	 * Freezes what this chunk spent into the entry the job record carries.
	 *
	 * @return Chunk_Timing The immutable record of this chunk's phases.
	 */
	public function timing(): Chunk_Timing {

		return new Chunk_Timing( time(), $this->elapsed );

	}

}
