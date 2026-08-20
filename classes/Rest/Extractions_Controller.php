<?php
/**
 * REST controller that creates Extraction jobs and reports their state.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Rest;

use Kntnt\Extractor\Authorizer;
use Kntnt\Extractor\Config;
use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Extraction_Job;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Job_Store;
use Kntnt\Extractor\Restricted_Path;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers and answers `POST /extractions`, `GET /extractions`,
 * `GET /extractions/{id}`, `POST /extractions/{id}/consume`,
 * `DELETE /extractions/{id}`, and the internal `POST /extractions/{id}/tick`.
 *
 * `GET /extractions` (no id) lists the caller's own non-terminal jobs behind the
 * same both-capabilities gate, so a job stranded by a crashed run can be found and
 * cancelled before it blocks the next create for the whole TTL window (ADR-0004);
 * it never discloses another user's job or a download link.
 *
 * `POST /extractions` turns an already-resolved selection of tables and/or files,
 * plus the caller's ephemeral X25519 public key, into a queued Extraction job
 * bound to the caller (ADR-0004) and schedules the first continuation that starts
 * its execution for after the 201 is sent. `GET /extractions/{id}` reports that
 * job's state to its owner, hands back the sealed artifact's download link once the
 * job is ready, and schedules a stalled queue's continuation for after the
 * response, never coupling its poll latency to loopback health (ADR-0010).
 * `POST /extractions/{id}/tick` is the
 * internal driver endpoint: authenticated by the job's own secret rather than by a
 * capability, so the loopback loop can advance the job without a session (ADR-0007),
 * it is the one route here that is not behind the capability gate.
 *
 * The last two routes end a job's life (ADR-0004). `POST /extractions/{id}/consume`
 * is the caller's confirmation that it fetched and unsealed a ready artifact: the
 * server deletes the artifact and the job's working directory and reports the job
 * consumed, refusing any job that is not ready with a 409. `DELETE /extractions/{id}`
 * is the caller's abort — it cleans up a job in any state without writing an audit
 * record, since the audit log is filed only when a job reaches ready, never here.
 * Both bind to the owner: existence is decided before ownership, so a capable
 * non-owner is refused 403 without ever learning a job's state, and an unknown id is
 * a 404. Both also take the job's per-job tick lock before deleting anything
 * ({@see Job_Store::lock()}), the same lock the internal tick driver and the TTL
 * sweep already take, so a live build is never deleted out from under itself; a lock
 * that cannot be taken is a 409 naming the reason, never a silent skip and never a
 * wait (ADR-0019).
 *
 * Two caps stand ahead of that whole ladder, and ahead of the capability gate with
 * it (ADR-0020): a raw body larger than `max_body_bytes` is a 413 refused before the
 * body is decoded, and a combined `tables` + `tables_structure_only` + `files`
 * selection larger than `max_selection_elements` is a 422 refused before any element
 * of it reaches a `realpath()` call or the table catalog. They exist because the
 * order below is what an unauthenticated caller gets to provoke for free, and they
 * bound what that costs; a request inside both is validated in exactly the order
 * this paragraph describes, unchanged.
 *
 * The order the create request is validated in is a security property, not an
 * incidental one (ADR-0003): a malformed body is a 422, an absent or malformed
 * key a 400, a selection naming a credential-bearing restricted path (ADR-0011)
 * a 422 naming every offending path, and an unknown table or a file resolving
 * outside the installation root a 404 that names every missing table and every
 * missing file in `data` — and that 404 is decided BEFORE the
 * capability gate, so the plugin rejects a request for something that does not
 * exist without first disclosing whether the caller could have been authorized.
 * The restricted-path check runs before the existence check for the same
 * reason: whether a denied path exists is not disclosed either. Only once
 * existence holds does the shared both-capabilities Authorizer get to refuse an
 * unauthorized caller with 403. The out-of-root check is a `realpath` boundary,
 * never a sanitiser: a traversal path is rejected outright, not rewritten into
 * a safe one. An optional `strict` member, defaulting to true, is the one
 * exception to a vanished *file* being a 404: `strict: false` drops those
 * paths from the selection, records them on the job, and still 404s a missing
 * table, a traversal, or a selection that is empty after the skip.
 *
 * @since 0.1.0
 */
final class Extractions_Controller {

	/**
	 * Combined `tables` + `tables_structure_only` + `files` elements one selection
	 * may carry when the knob does not override it.
	 *
	 * Measured against a real production selection — 186 tables and 49,116 files
	 * (ADR-0014) — and set at roughly ten times that combined count, so a
	 * legitimate clone of a site an order of magnitude larger than the one this was
	 * measured against still clears it. A cap that refuses the one large job this
	 * plugin exists to do would be the worse failure (ADR-0020). Resolved
	 * through the Config seam under `max_selection_elements`, so a site raises it
	 * with the `KNTNT_EXTRACTOR_MAX_SELECTION_ELEMENTS` constant or the
	 * `kntnt_extractor_config_max_selection_elements` filter.
	 *
	 * @since 0.6.1
	 */
	private const int DEFAULT_MAX_SELECTION_ELEMENTS = 500_000;

	/**
	 * Raw request-body bytes `POST /extractions` accepts when the knob does not
	 * override it.
	 *
	 * A synthetic payload shaped like the same production selection — 186 tables,
	 * 49,116 files, path samples deliberately longer than the real run's — encodes
	 * to 4,687,437 bytes; this is roughly eleven times that. It is a separate knob
	 * from the element cap because it bounds a different cost: a body carrying few
	 * elements of enormous individual strings passes the count and still has to be
	 * decoded. Resolved through the Config seam under `max_body_bytes`, so a site
	 * raises it with the `KNTNT_EXTRACTOR_MAX_BODY_BYTES` constant or the
	 * `kntnt_extractor_config_max_body_bytes` filter.
	 *
	 * @since 0.6.1
	 */
	private const int DEFAULT_MAX_BODY_BYTES = 52_428_800;

	/**
	 * Wires the controller to the access gate, the Config seam, the job store, and
	 * the driver.
	 *
	 * The Config seam is back, and reads exactly two knobs: the selection-element
	 * cap and the request-body byte cap this endpoint bounds an unauthenticated
	 * caller's pre-authorization work with (ADR-0020). The concurrency ceiling
	 * is not among them — it belongs to {@see Job_Store::has_free_slot()} beside the
	 * count it bounds, and is read there.
	 *
	 * @since 0.1.0
	 *
	 * @param Authorizer $authorizer The shared both-capabilities access gate.
	 * @param Config     $config     The constant-then-filter configuration seam.
	 * @param Job_Store  $store      Persistence for Extraction jobs.
	 * @param Dispatcher $dispatcher Drives a job forward and nudges a stalled queue.
	 */
	public function __construct(
		private readonly Authorizer $authorizer,
		private readonly Config $config,
		private readonly Job_Store $store,
		private readonly Dispatcher $dispatcher,
	) {}

	/**
	 * Registers every extraction route. Hooked on `rest_api_init`.
	 *
	 * The collection `/extractions` path answers two methods: a `POST` creates a job,
	 * and a `GET` lists the caller's own non-terminal jobs behind the shared
	 * both-capabilities gate. The create route's permission callback runs the whole
	 * existence-and-key validation before the capability check, which is what lets a
	 * 404 or 400 precede the 403 (ADR-0003); the list route only needs the capability
	 * gate itself, plus the optional `state` argument's own schema — an enum of
	 * exactly `all`, checked by a `validate_callback` rather than trusted as a free
	 * string, so anything else is refused with `400 rest_invalid_param` before the
	 * callback ever runs (ADR-0019). The id-addressed routes capture a 32-hex id
	 * straight from the path, so a malformed id never matches and never reaches the
	 * store. Poll and cancel
	 * share one route path — a `GET` reads the job, a `DELETE` cancels it — behind the
	 * same capability gate, with the per-job ownership binding layered on inside each
	 * callback.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			Status_Controller::REST_NAMESPACE,
			'/extractions',
			[
				[
					'methods' => WP_REST_Server::CREATABLE,
					'callback' => $this->create( ... ),
					'permission_callback' => $this->can_create( ... ),
				],
				[
					'methods' => WP_REST_Server::READABLE,
					'callback' => $this->list_jobs( ... ),
					'permission_callback' => $this->authorizer->authorize( ... ),
					'args' => [
						'state' => [
							'type' => 'string',
							'required' => false,
							'validate_callback' => static fn( mixed $value ): bool => $value === 'all',
							'description' => __( 'Set to "all" to additionally list the caller\'s own terminal jobs.', 'kntnt-extractor' ),
						],
					],
				],
			],
		);

		register_rest_route(
			Status_Controller::REST_NAMESPACE,
			'/extractions/(?P<id>[a-f0-9]{32})',
			[
				[
					'methods' => WP_REST_Server::READABLE,
					'callback' => $this->poll( ... ),
					'permission_callback' => $this->authorizer->authorize( ... ),
				],
				[
					'methods' => WP_REST_Server::DELETABLE,
					'callback' => $this->cancel( ... ),
					'permission_callback' => $this->authorizer->authorize( ... ),
				],
			],
		);

		register_rest_route(
			Status_Controller::REST_NAMESPACE,
			'/extractions/(?P<id>[a-f0-9]{32})/consume',
			[
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => $this->consume( ... ),
				'permission_callback' => $this->authorizer->authorize( ... ),
			],
		);

		register_rest_route(
			Status_Controller::REST_NAMESPACE,
			'/extractions/(?P<id>[a-f0-9]{32})/tick',
			[
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => $this->tick( ... ),
				'permission_callback' => $this->can_tick( ... ),
			],
		);

	}

	/**
	 * Permission callback for creating a job: validate the request, then authorize.
	 *
	 * The request is fully validated first — size (413/422), body shape (422),
	 * public key (400), and resource existence (404) — and only a request that
	 * survives all of them reaches the capability gate. The two size caps are what
	 * bound the cost of the rest, since everything this method runs, it runs for an
	 * anonymous caller who has proved nothing (ADR-0020). Running validation here
	 * rather than in the main
	 * callback is deliberate: WordPress runs the permission callback before the
	 * callback, so this is the seam where a 404 can be made to precede the 403 the
	 * capability gate would otherwise return first (ADR-0003).
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming create request.
	 * @return true|WP_Error True once the request is valid and authorized; otherwise
	 *                       the first failing check as a 422, 400, 404, or 403.
	 */
	public function can_create( WP_REST_Request $request ): true|WP_Error {

		// Reject a malformed, keyless, or non-existent-resource request before the
		// capability gate ever runs; only then let the Authorizer have its say.
		$payload = $this->validate_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return $this->authorizer->authorize();

	}

	/**
	 * Creates the queued job and returns its id and state.
	 *
	 * The request has already passed validation and the capability gate, so the
	 * only new gate here is concurrency: a second non-terminal job beyond the
	 * configured ceiling is refused with 429. The payload is re-derived from the
	 * request — parsing it is how this callback obtains its inputs, not a second
	 * validation of them. The job's first continuation is scheduled for after this
	 * 201 is sent, so no loopback or packaging work precedes the response (ADR-0010).
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming create request.
	 * @return WP_REST_Response|WP_Error A 201 with `{ id, state, skipped_files? }`, or a
	 *                                   429 when the concurrency ceiling is already reached.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		// Re-derive the validated payload; a failure cannot occur after can_create
		// but the union return type must still be honoured.
		$payload = $this->validate_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Enforce the global concurrency ceiling: a create beyond it is a 429, the
		// caller's cue to poll or consume the active job before starting another.
		if ( ! $this->store->has_free_slot() ) {
			return new WP_Error(
				'kntnt_extractor_too_many_jobs',
				__( 'Another extraction is already in progress. Wait for it to finish before starting another.', 'kntnt-extractor' ),
				[ 'status' => 429 ],
			);
		}

		// Persist a queued job bound to the caller, then schedule its first continuation
		// for after this 201 is sent, so the response never waits on loopback or
		// packaging work — the job's execution begins post-response (ADR-0007/0010).
		$job = $this->store->create( get_current_user_id(), $payload['public_key'], $payload['tables'], $payload['structure_only'], $payload['files'], $payload['skipped_files'] );
		$this->dispatcher->continue_after_response( $job );

		// Echo the id and queued state; name skipped files only when a strict: false
		// create actually dropped any, matching the poll's missing-key optionality.
		$response = [
			'id' => $job->id,
			'state' => $job->state->value,
		];
		if ( $job->skipped_files !== [] ) {
			$response['skipped_files'] = $job->skipped_files;
		}

		return new WP_REST_Response(
			$response,
			201,
		);

	}

	/**
	 * Lists the caller's own jobs: non-terminal by default, or including terminal ones.
	 *
	 * The collection surface the cutover health check enumerates its live jobs
	 * through, so a job stranded by a crashed earlier run — queued, running, or ready,
	 * holding the single global concurrency slot until the TTL sweep reclaims it
	 * (ADR-0004) — can be found and cancelled rather than blocking the next create for
	 * up to the sweep window. The route's permission callback has already run the
	 * both-capabilities gate (ADR-0002), so this only applies the owner scoping and the
	 * state filter: {@see Job_Store::all()} is narrowed to the caller's own jobs, and a
	 * caller never sees another user's job whatever its state.
	 *
	 * With no `state` parameter the filter is exactly what it always was — non-terminal
	 * only — so every existing client's request returns byte-identical output (ADR-0019).
	 * `state=all` additionally admits the caller's own terminal jobs, answering "is there
	 * anything of mine still on this site" for the one case the default was built to
	 * exclude: a failed, cancelled, expired, or consumed job leaves no other trace at
	 * this surface, and consume/cancel/the sweep purge the working directory the moment
	 * a job ends, so the answer this gives is "does a record of it still exist", not
	 * "is there still an artifact to fetch" — there never is one for a terminal entry.
	 *
	 * A non-terminal entry carries the same id, state, and timestamps a create and poll
	 * report, plus `progress` on exactly the jobs that have advanced — running or ready,
	 * in the poll's four-counter shape and missing-key optionality. A terminal entry
	 * (only ever present under `state=all`) carries just id, state, and the timestamps:
	 * enough to answer the exposure question and nothing that could be mistaken for an
	 * invitation to act on it. Neither shape ever carries a `download_url`: fetching an
	 * artifact stays the per-job {@see poll()} contract's job, and every terminal job's
	 * artifact is gone by the time it can appear here anyway. The caller with no
	 * matching jobs gets an empty array.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Request $request The incoming list request, carrying the optional `state` argument.
	 * @return WP_REST_Response A 200 with `{ jobs: [ { id, state, created_at, updated_at, progress? } ] }`.
	 */
	public function list_jobs( WP_REST_Request $request ): WP_REST_Response {

		// Scope the enumeration to the caller's own jobs, always skipping another
		// user's; state=all is the one thing that widens which of the caller's own
		// jobs are admitted, never whose jobs are.
		$owner = get_current_user_id();
		$include_terminal = $request->get_param( 'state' ) === 'all';
		$jobs = [];
		foreach ( $this->store->all() as $job ) {
			if ( $job->owner !== $owner ) {
				continue;
			}

			// A terminal job is reported only under state=all, and only with the
			// minimal shape the exposure question needs — never progress, never a
			// download_url, since neither exists for it any more.
			if ( $job->state->is_terminal() ) {
				if ( $include_terminal ) {
					$jobs[] = [
						'id' => $job->id,
						'state' => $job->state->value,
						'created_at' => $job->created_at,
						'updated_at' => $job->updated_at,
					];
				}
				continue;
			}

			// Report the fields every listed non-terminal job carries, then append
			// progress only where the job has advanced — the same optionality and
			// four-counter shape the poll uses, and never a download_url.
			$entry = [
				'id' => $job->id,
				'state' => $job->state->value,
				'created_at' => $job->created_at,
				'updated_at' => $job->updated_at,
			];
			$progress = $this->progress_of( $job );
			if ( $progress !== null ) {
				$entry['progress'] = $progress;
			}
			$jobs[] = $entry;
		}

		return new WP_REST_Response( [ 'jobs' => $jobs ] );

	}

	/**
	 * Reports a job's state to its owner.
	 *
	 * An unknown id is a 404 and a job owned by someone else is a 403 — existence
	 * before ownership, mirroring the create path's existence-before-capability
	 * order. The capability gate has already admitted the caller through the
	 * route's permission callback, so this only adds the per-job ownership binding
	 * (AC4). The stalled-queue continuation is scheduled for after the response, so
	 * the poll never blocks on loopback or packaging work (ADR-0010).
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming poll request, carrying the id.
	 * @return WP_REST_Response|WP_Error A 200 with `{ id, state, download_url }` plus
	 *                                   `progress` while running or ready, `error`
	 *                                   once failed, `skipped_files` when a
	 *                                   `strict: false` create dropped vanished
	 *                                   files, and `attempts` once any chunk has
	 *                                   been begun; a 404 for an unknown job, or a
	 *                                   403 for a non-owner.
	 */
	public function poll( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		// Resolve the caller's own job; an unknown id is a 404 and a non-owner a 403.
		$job = $this->resolve_owned_job( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		// Schedule the continuation for after this response is sent — never inline, so
		// the poll's latency is independent of loopback health (ADR-0010). Post-detach
		// it drives a queued or stalled job in-process; otherwise it is the same guarded
		// nudge, now paid after the body is echoed and left alone for a job being ticked.
		$this->dispatcher->continue_after_response( $job );

		// Start from the fields every poll carries: the id, the current state, and the
		// download link — null until the sealed artifact is published at ready.
		$response = [
			'id' => $job->id,
			'state' => $job->state->value,
			'download_url' => $this->store->download_url( $job ),
		];

		// Append the state-scoped optional fields of the v1 poll contract: progress while
		// the build is advancing (and complete once ready), a reason once it failed,
		// skipped files when a strict: false create dropped vanished paths, and the
		// bounded attempt log once any chunk has been begun (ADR-0016). Missing-key
		// optionality — a field the contract marks absent is omitted, never sent as
		// null — so `progress?`/`error?`/`skipped_files?`/`attempts?` read exactly as
		// the spec defines.
		$progress = $this->progress_of( $job );
		if ( $progress !== null ) {
			$response['progress'] = $progress;
		}
		$error = $this->error_of( $job );
		if ( $error !== null ) {
			$response['error'] = $error;
		}
		if ( $job->skipped_files !== [] ) {
			$response['skipped_files'] = $job->skipped_files;
		}
		$attempts = $this->attempts_of( $job );
		if ( $attempts !== [] ) {
			$response['attempts'] = $attempts;
		}

		return new WP_REST_Response( $response );

	}

	/**
	 * Summarises a job's build advancement as the poll contract's `progress?`, or null.
	 *
	 * Reported while running and, reading as complete, once ready — omitted for every
	 * other state, matching the field's optionality (a queued job has started nothing).
	 * The shape is deliberately a stable, caller-facing summary derived from the job:
	 * how many of the selected tables and files are done out of their totals, plus how
	 * many packaging chunks have been committed. It never surfaces the internal
	 * {@see Build_Progress} mechanics themselves — segment names, byte offsets, keyset
	 * cursors, the sealed-index detail — which are resume bookkeeping coupled to the
	 * on-disk container format (ADR-0007/0009), not a caller concern (AC5); a bare
	 * count of committed chunks discloses nothing about them beyond how much work has
	 * happened, which is the caller's own question. No derived percentage is offered:
	 * a file's total byte size is not known up front, so a percentage would mislead,
	 * whereas discrete counters are honest advancement.
	 *
	 * A running job that has not yet sealed its first segment carries no persisted
	 * progress, which reads as nothing-done-yet (zero counters) rather than a missing
	 * field, so a poll during the very first chunk still reports the totals.
	 *
	 * `chunks_done` is the fifth counter and the only fine-grained one. The four above
	 * advance only when a whole table or a whole file is finished, which since tables
	 * became sliceable (ADR-0013) means a job working steadily through one large table
	 * or one large file reports counters identical to a wedged job's — observed in the
	 * field as `3/186` standing still for minutes while the run was perfectly healthy,
	 * and answered by clients widening their stall windows until a real stall took
	 * hours to notice. This counts packaging chunks instead: one table slice, one
	 * structure-only table, or one file part, so it moves on every chunk the build
	 * seals and a stall rule watching it distinguishes slow from stuck. It carries no
	 * total, because how many slices a table takes is not knowable before it is dumped
	 * — it is a liveness signal, not a completion ratio. On a ready job it equals the
	 * artifact's segment count: the finalizing step reports its progress like any
	 * other ({@see Build_Step}), so the chunk that publishes the container is counted
	 * rather than lost.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The polled job.
	 * @return array{tables_done: int, tables_total: int, files_done: int, files_total: int, chunks_done: int}|null
	 */
	private function progress_of( Extraction_Job $job ): ?array {

		// Structure-only tables are tables to the caller's eye, so they count toward the
		// table totals alongside the full-data ones (issue #16, AC5): a single honest
		// "N of M tables" that spans both selections, full then structure-only.
		$tables_total = count( $job->tables ) + count( $job->structure_only );
		$files_total = count( $job->files );
		$chunks_done = $job->progress === null ? 0 : $job->progress->segment_count;

		// A ready job is complete by definition, so report every table and file done
		// rather than the penultimate chunk's persisted progress. A running job reports
		// the sealed counts its persisted progress carries — full-data plus structure-only
		// table segments — or zero before the first chunk.
		return match ( $job->state ) {
			Job_State::Ready => [
				'tables_done' => $tables_total,
				'tables_total' => $tables_total,
				'files_done' => $files_total,
				'files_total' => $files_total,
				'chunks_done' => $chunks_done,
			],
			Job_State::Running => [
				'tables_done' => $job->progress === null ? 0 : $job->progress->tables_done + $job->progress->structure_done,
				'tables_total' => $tables_total,
				'files_done' => $job->progress === null ? 0 : $job->progress->file_index,
				'files_total' => $files_total,
				'chunks_done' => $chunks_done,
			],
			default => null,
		};

	}

	/**
	 * Reports the poll contract's `error?` for a failed job, or null for any other state.
	 *
	 * The field's shape is unchanged — a `message` and nothing more — but the message is
	 * the reason the job recorded when the plugin diagnosed the failure itself, falling
	 * back to a generic one otherwise. Both failures the plugin can see write a reason:
	 * the stalled build (ADR-0013) composes one from the caller's own selection and two
	 * runtime settings, and an unexpected throw composes one naming the throwable,
	 * bounded and trace-free so the arbitrary string it relays cannot carry a whole
	 * query or a call stack out to the caller (ADR-0007). The fallback is therefore no
	 * longer the ordinary case but a diagnosis of its own: a failed job reporting it
	 * reached no `catch` at all, so the PHP process was killed rather than throwing.
	 * Every non-failed state omits the field.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The polled job.
	 * @return array{message: string}|null The failure message, or null when the job has not failed.
	 */
	private function error_of( Extraction_Job $job ): ?array {

		return $job->state === Job_State::Failed
			? [ 'message' => $job->error ?? __( 'The extraction failed.', 'kntnt-extractor' ) ]
			: null;

	}

	/**
	 * Reports the poll contract's `attempts?` — the bounded last-N of begun chunks.
	 *
	 * A debug surface, not the audit log (ADR-0016): each entry names the chunk a
	 * tick began, resolved from the caller's own selection at read time so the
	 * persisted record never holds a path. Empty until the first tick, and then
	 * last-N, so a queued poll omits the member and a long run never grows without
	 * bound. The sealed index has no name.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The polled job.
	 * @return list<array{at: int, kind: string, offset: int, name?: string}>
	 */
	private function attempts_of( Extraction_Job $job ): array {

		$attempts = [];
		foreach ( $job->attempt_log as $attempt ) {

			// Project the persisted scalars and resolve the caller-facing name
			// from the selection, so the state file never held a path.
			$entry = [
				'at' => $attempt->at,
				'kind' => $attempt->kind->value,
				'offset' => $attempt->offset,
			];
			$name = $attempt->name_on( $job );
			if ( $name !== null ) {
				$entry['name'] = $name;
			}
			$attempts[] = $entry;

		}

		return $attempts;

	}

	/**
	 * Consumes a ready job: deletes its artifact and working directory, marks it consumed.
	 *
	 * The caller's confirmation that it has fetched and unsealed the artifact, so the
	 * server removes both the sealed artifact and the job's working directory and
	 * reports the job consumed (ADR-0004). Only a ready job can be consumed — any other
	 * state has no unconsumed artifact to confirm and is a 409. Existence precedes
	 * ownership precedes state, so a non-owner is refused 403 without ever learning the
	 * job's state, and the audit record written earlier at ready (ADR-0006) is a
	 * separate file this deletion never touches.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming consume request, carrying the id.
	 * @return WP_REST_Response|WP_Error A 200 with `{ id, state: consumed }`; a 404 for an
	 *                                   unknown job, a 403 for a non-owner, or a 409 when
	 *                                   the job is not ready or its tick lock is held.
	 */
	public function consume( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		// Resolve the caller's own job; an unknown id is a 404 and a non-owner a 403.
		$job = $this->resolve_owned_job( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		// Consume confirms a ready artifact; a job in any other state has nothing to
		// confirm and is a conflict, revealed only now that ownership holds.
		if ( $job->state !== Job_State::Ready ) {
			return $this->error( 409, 'kntnt_extractor_not_ready', __( 'Only a ready extraction job can be consumed.', 'kntnt-extractor' ) );
		}

		// Take the same per-job tick lock a live tick holds ({@see Sweeper}) before
		// purging: this deletion reaches the artifact and the working directory a
		// driver may still be writing into, not merely a resource other callers race
		// for, so it must take the lock exactly as the tick and the sweep already do
		// rather than trust that a ready job's builder is always finished. A ready job
		// can only ever race a tick in the narrow window between that tick publishing
		// the artifact and releasing its own lock, so this should not normally fail —
		// but when it does, a 409 is the honest answer and the caller can simply retry.
		$lock = $this->store->lock( $job );
		if ( $lock === null ) {
			return $this->error( 409, 'kntnt_extractor_locked', __( 'This extraction job is being built; retry the request.', 'kntnt-extractor' ) );
		}
		try {
			$this->store->purge( $job );
		} finally {
			$this->store->unlock( $lock );
		}

		return new WP_REST_Response(
			[
				'id' => $job->id,
				'state' => Job_State::Consumed->value,
			],
		);

	}

	/**
	 * Cancels a job: deletes its artifact and working directory without an audit record.
	 *
	 * Unlike consume, cancel is the caller's abort and applies to a job in any state it
	 * owns — queued, running, or ready — removing the artifact and the working directory
	 * and reporting the job cancelled. It writes no audit record: the audit log is filed
	 * only when a job reaches ready (ADR-0004/0006), a transition cancel never causes.
	 * Existence precedes ownership, so a non-owner is refused 403 and an unknown id is a
	 * 404.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming cancel request, carrying the id.
	 * @return WP_REST_Response|WP_Error A 200 with `{ id, state: cancelled }`; a 404 for an
	 *                                   unknown job, a 403 for a non-owner, or a 409 when
	 *                                   its tick lock is held.
	 */
	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		// Resolve the caller's own job; an unknown id is a 404 and a non-owner a 403.
		$job = $this->resolve_owned_job( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		// Take the job's tick lock before purging, exactly as consume and the sweep do:
		// unlike consume, cancel reaches a job in ANY state it owns — queued or running
		// included — so it is the caller most likely to race a live tick still packaging
		// that job's container. Purging without this lock is the defect this plan fixes:
		// a cancel landing mid-tick could delete the directory a driver is writing into,
		// and if the driver's publish then wins the race, a sealed artifact lands in the
		// served directory for a job whose record has just been deleted, orphaned from
		// that moment on. Refusing with 409 rather than blocking keeps this call
		// non-blocking like every other lock acquisition in the plugin; the caller
		// simply retries.
		$lock = $this->store->lock( $job );
		if ( $lock === null ) {
			return $this->error( 409, 'kntnt_extractor_locked', __( 'This extraction job is being built; retry the request.', 'kntnt-extractor' ) );
		}
		try {
			$this->store->purge( $job );
		} finally {
			$this->store->unlock( $lock );
		}

		return new WP_REST_Response(
			[
				'id' => $job->id,
				'state' => Job_State::Cancelled->value,
			],
		);

	}

	/**
	 * Permission callback for the internal tick endpoint: the per-job secret alone.
	 *
	 * The tick is driven by the loopback loop, which carries no WordPress session, so
	 * it is authenticated by the job's own secret rather than by a capability — an
	 * outsider without the secret cannot drive the job, and neither can even a capable
	 * owner (ADR-0007). An unknown job and an absent or wrong secret are refused
	 * identically, so the endpoint reveals nothing about which job ids exist. The
	 * comparison is constant-time to keep the secret out of a timing side channel.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming tick request, carrying the id and secret.
	 * @return true|WP_Error True when the secret matches the job; a 403 otherwise.
	 */
	public function can_tick( WP_REST_Request $request ): true|WP_Error {

		// Resolve the job and require its exact secret; any failure is one uniform 403
		// so the endpoint is not an existence oracle.
		$raw_id = $request->get_param( 'id' );
		$job = $this->store->find( is_string( $raw_id ) ? $raw_id : '' );
		$provided = $request->get_header( Dispatcher::TICK_SECRET_HEADER );
		if ( $job === null || ! is_string( $provided ) || $provided === '' || ! hash_equals( $job->tick_secret, $provided ) ) {
			return new WP_Error(
				'kntnt_extractor_forbidden',
				__( 'A valid per-job tick secret is required.', 'kntnt-extractor' ),
				[ 'status' => 403 ],
			);
		}

		return true;

	}

	/**
	 * Advances a job one tick and reports the state it reached.
	 *
	 * The permission callback has already authenticated the secret and proven the job
	 * exists, so this reloads it and hands it to the driver. The driver advances a
	 * queued or still-building job by one bounded chunk and leaves a ready or terminal
	 * one untouched; overlapping ticks are serialised by a per-job lock there, so a
	 * duplicate or racing loopback is a harmless no-op. The job can still be swept
	 * between the permission check and here, which reads as a 404 rather than a fatal on
	 * a vanished record.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming tick request, carrying the id.
	 * @return WP_REST_Response|WP_Error A 200 with the job's `{ id, state }` after the
	 *                                   tick, or a 404 when the job no longer exists.
	 */
	public function tick( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		// Reload the job the authenticated request named; a job swept between the
		// permission check and here is simply gone.
		$raw_id = $request->get_param( 'id' );
		$job = $this->store->find( is_string( $raw_id ) ? $raw_id : '' );
		if ( $job === null ) {
			return new WP_Error(
				'kntnt_extractor_no_such_job',
				__( 'No such extraction job.', 'kntnt-extractor' ),
				[ 'status' => 404 ],
			);
		}

		// Advance the surviving job one tick and report the state it reached.
		$advanced = $this->dispatcher->tick( $job );

		return new WP_REST_Response(
			[
				'id' => $advanced->id,
				'state' => $advanced->state->value,
			],
		);

	}

	/**
	 * Resolves the request's id to the caller's own job, or the failing check.
	 *
	 * Existence is decided before ownership — an id naming no readable job is a 404,
	 * and a job owned by someone else is a 403 — so the endpoint never discloses to a
	 * non-owner whether a job exists by answering with a different status. This is the
	 * per-job binding every id-addressed route (poll, consume, cancel) shares (AC4/AC5),
	 * layered on top of the capability gate the route's permission callback already ran.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming id-addressed request.
	 * @return Extraction_Job|WP_Error The caller's own job, or a 404 or 403.
	 */
	private function resolve_owned_job( WP_REST_Request $request ): Extraction_Job|WP_Error {

		// Resolve the id to a job; an id naming no readable job is a 404.
		$raw_id = $request->get_param( 'id' );
		$job = $this->store->find( is_string( $raw_id ) ? $raw_id : '' );
		if ( $job === null ) {
			return $this->error( 404, 'kntnt_extractor_no_such_job', __( 'No such extraction job.', 'kntnt-extractor' ) );
		}

		// Bind the job to its creator: a caller who is not the owner is refused, even
		// though the capability gate already admitted them.
		if ( $job->owner !== get_current_user_id() ) {
			return $this->error( 403, 'kntnt_extractor_forbidden', __( 'This extraction job belongs to another user.', 'kntnt-extractor' ) );
		}

		return $job;

	}

	/**
	 * Validates a create request into a resolved payload, or the first failing check.
	 *
	 * The checks run in the contract's fixed precedence, and the two size caps run
	 * ahead of all of them (ADR-0020): a raw body over `max_body_bytes` is a 413
	 * decided before `json_decode()` sees it, and a combined selection over
	 * `max_selection_elements` is a 422 decided as soon as the three arrays are
	 * shape-checked, before the overlap check and everything after it.
	 *
	 * Then the ladder proper: a body that is not a JSON object, or a selection that is
	 * not a list of non-empty strings, or one that selects nothing, or one that lists
	 * a table as both full-data and structure-only, is a 422; an absent or malformed public key is a 400; a file matching the
	 * credential-bearing deny-list (ADR-0011) is a 422 naming every offending path;
	 * an unknown table (full-data or structure-only) or a file resolving outside the
	 * installation root is a 404 naming every missing table and every missing file.
	 * Existence is deliberately the last of these so a
	 * well-formed request is never told a resource is missing before it is told its
	 * own shape is wrong or that it selects a restricted path, yet still ahead of the
	 * capability gate its caller runs afterwards.
	 *
	 * `strict` is optional and defaults to true, which is today's hard fail. A
	 * present non-boolean is a 422. `strict: false` drops vanished files from the
	 * selection (and reports them as `skipped_files`) but still 404s a missing
	 * table, a traversal, a null-byte path, or a selection that is empty once
	 * the vanished files are gone. Old clients that omit the member keep the
	 * behaviour they already understood, which is why `strict` itself required
	 * no version bump on its own.
	 *
	 * The structure-only selection (issue #16) is additive and independently
	 * omittable: an absent or null `tables_structure_only` behaves as `[]`, so a
	 * pre-#16 caller sending only `tables`/`files` is unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming create request.
	 * @return array{tables: array<int, string>, structure_only: array<int, string>, files: array<int, string>, public_key: string, skipped_files: array<int, string>}|WP_Error
	 */
	private function validate_payload( WP_REST_Request $request ): array|WP_Error {

		// Refuse an oversized body before it is decoded: an unauthenticated caller
		// must not be able to spend json_decode() time and memory proportional to a
		// body of unbounded size (ADR-0020). The refusal reports the limit and
		// the caller's own size, because a client that hits this needs the number to
		// shrink its request by, not merely the news that a number exists.
		$body = (string) $request->get_body();
		$body_bytes = strlen( $body );
		$max_body_bytes = $this->max_body_bytes();
		if ( $body_bytes > $max_body_bytes ) {
			return new WP_Error(
				'kntnt_extractor_payload_too_large',
				__( 'The request body exceeds the maximum accepted size.', 'kntnt-extractor' ),
				[
					'status' => 413,
					'limit' => $max_body_bytes,
					'bytes' => $body_bytes,
				],
			);
		}

		// Parse the body; anything that is not a JSON object is a malformed body.
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return $this->error( 422, 'kntnt_extractor_malformed_body', __( 'The request body must be a JSON object.', 'kntnt-extractor' ) );
		}

		// Normalise the three selections; a present-but-ill-typed selection, one holding
		// an empty entry, or a request that selects nothing at all is a malformed body.
		// A structure-only-only selection is valid, so nothing-selected weighs all three.
		$tables = $this->string_selection( $data['tables'] ?? [] );
		$structure_only = $this->string_selection( $data['tables_structure_only'] ?? [] );
		$files = $this->string_selection( $data['files'] ?? [] );
		if ( $tables === null || $structure_only === null || $files === null || ( $tables === [] && $structure_only === [] && $files === [] ) ) {
			return $this->error( 422, 'kntnt_extractor_malformed_body', __( 'Provide tables, tables_structure_only, and/or files as arrays of non-empty strings, selecting at least one.', 'kntnt-extractor' ) );
		}

		// Bound the whole selection before any element of it reaches a realpath() call
		// or a catalog comparison: an oversized selection is refused ahead of the
		// restricted-path check, the existence check, and the capability gate alike
		// (ADR-0020), on three counts of arrays whose shape is already settled.
		$selection_elements = count( $tables ) + count( $structure_only ) + count( $files );
		$max_selection_elements = $this->max_selection_elements();
		if ( $selection_elements > $max_selection_elements ) {
			return new WP_Error(
				'kntnt_extractor_selection_too_large',
				__( 'The selection exceeds the maximum number of tables and files accepted in one request.', 'kntnt-extractor' ),
				[
					'status' => 422,
					'limit' => $max_selection_elements,
					'count' => $selection_elements,
				],
			);
		}

		// A table is either dumped whole or structure-only, never both: the same name in
		// both lists is a contradictory request, a malformed body rather than a not-found.
		if ( array_intersect( $tables, $structure_only ) !== [] ) {
			return $this->error( 422, 'kntnt_extractor_overlapping_selection', __( 'A table may appear in tables or tables_structure_only, but not both.', 'kntnt-extractor' ) );
		}

		// A present-but-non-boolean strict is a malformed body; an omitted one is
		// today's hard fail, so an old client that never heard of the member is
		// unchanged.
		$strict = $data['strict'] ?? true;
		if ( ! is_bool( $strict ) ) {
			return $this->error( 422, 'kntnt_extractor_malformed_body', __( 'strict must be a boolean when provided.', 'kntnt-extractor' ) );
		}

		// Require a well-formed key: present, valid base64, exactly a 32-byte X25519
		// public key. Its absence or malformation is a client error, not a not-found.
		$public_key = $this->canonical_public_key( $data['public_key'] ?? null );
		if ( $public_key === null ) {
			return $this->error( 400, 'kntnt_extractor_invalid_public_key', __( 'A valid base64-encoded 32-byte X25519 public key is required.', 'kntnt-extractor' ) );
		}

		// Restricted-path rejection runs before the existence check: a selection
		// naming a credential-bearing file (ADR-0011) is refused outright, naming
		// every offending path, and its mere existence is never disclosed by
		// letting it fall through to the existence check's outcome.
		$restricted = Restricted_Path::matches( $files );
		if ( $restricted !== [] ) {
			return new WP_Error(
				'kntnt_extractor_restricted_path',
				sprintf(
					/* translators: %s: a comma-separated list of the restricted file paths the caller selected. */
					__( 'The selection includes restricted path(s) that cannot be extracted: %s', 'kntnt-extractor' ),
					implode( ', ', $restricted ),
				),
				[
					'status' => 422,
					'paths' => $restricted,
				],
			);
		}

		// Existence-first: collect every missing table and every file that does not
		// resolve inside the root, then 404 naming all of them (ADR-0003). A missing
		// table always fails. A vanished file fails unless the caller asked for
		// `strict: false`, in which case it is dropped and reported; a traversal or
		// a null-byte path is never a skip.
		$missing_tables = [ ...$this->missing_tables( $tables ), ...$this->missing_tables( $structure_only ) ];
		$classified = $this->classify_files( $files );
		$missing_files = [ ...$classified['vanished'], ...$classified['out_of_bounds'] ];
		$skipped_files = ( ! $strict ) ? $classified['vanished'] : [];
		$kept_files = ( ! $strict ) ? $classified['kept'] : $files;
		$hard_missing_files = $strict ? $missing_files : $classified['out_of_bounds'];
		if ( $missing_tables !== [] || $hard_missing_files !== [] || ( $kept_files === [] && $tables === [] && $structure_only === [] ) ) {
			return new WP_Error(
				'kntnt_extractor_unknown_resource',
				__( 'A requested table or file does not exist within this installation.', 'kntnt-extractor' ),
				[
					'status' => 404,
					'tables' => $missing_tables,
					'files' => $missing_files,
				],
			);
		}

		return [
			'tables' => $tables,
			'structure_only' => $structure_only,
			'files' => $kept_files,
			'public_key' => $public_key,
			'skipped_files' => $skipped_files,
		];

	}

	/**
	 * Resolves the combined selection-element cap through the Config seam, clamped
	 * to at least one.
	 *
	 * A non-numeric override is ignored in favour of the default rather than coerced
	 * to a meaningless zero, and the floor of one keeps the endpoint usable however
	 * the knob is misconfigured — the same discipline
	 * {@see Job_Store::max_active_jobs()} applies to its own ceiling.
	 *
	 * @since 0.6.1
	 *
	 * @return int The most combined elements one selection may carry across tables,
	 *             tables_structure_only, and files.
	 */
	private function max_selection_elements(): int {

		$configured = $this->config->get( 'max_selection_elements', self::DEFAULT_MAX_SELECTION_ELEMENTS );

		return max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_MAX_SELECTION_ELEMENTS );

	}

	/**
	 * Resolves the request-body byte cap through the Config seam, clamped to at
	 * least one.
	 *
	 * @since 0.6.1
	 *
	 * @return int The largest raw request body, in bytes, this endpoint will decode.
	 */
	private function max_body_bytes(): int {

		$configured = $this->config->get( 'max_body_bytes', self::DEFAULT_MAX_BODY_BYTES );

		return max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_MAX_BODY_BYTES );

	}

	/**
	 * Coerces a selection into a list of non-empty strings, or null when it is not one.
	 *
	 * An absent selection arrives as `[]` and is a valid empty selection; a scalar,
	 * a map, or a list holding a non-string or empty string is not a selection at all.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The decoded `tables` or `files` value, or `[]` when absent.
	 * @return array<int, string>|null The selection as a list of non-empty strings, or null.
	 */
	private function string_selection( mixed $value ): ?array {

		// Only a list-shaped array whose every element is a non-empty string is a
		// valid selection; anything else disqualifies the request as malformed.
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}
		$selection = [];
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || $item === '' ) {
				return null;
			}
			$selection[] = $item;
		}

		return $selection;

	}

	/**
	 * Validates a caller public key and returns it in canonical base64, or null.
	 *
	 * The key crosses JSON as base64 and must decode to exactly a 32-byte X25519
	 * public key — the length the crypto seam seals with. The returned value is
	 * re-encoded from the decoded bytes so what is persisted is a single canonical
	 * form regardless of padding or alphabet quirks in what the caller sent.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The decoded `public_key` value, or null when absent.
	 * @return string|null Canonical base64 of the key, or null when it is invalid.
	 */
	private function canonical_public_key( mixed $value ): ?string {

		// Reject an absent, non-string, or empty key outright.
		if ( ! is_string( $value ) || $value === '' ) {
			return null;
		}

		// Require strict base64 that decodes to exactly the X25519 public-key length,
		// then hand back a canonical re-encoding of those bytes.
		$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a caller's public key from the JSON body, not obfuscating code.
		if ( $decoded === false || strlen( $decoded ) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES ) {
			return null;
		}

		return base64_encode( $decoded ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- re-encoding the validated public key to a canonical form for storage.

	}

	/**
	 * Returns every requested table that does not exist, in request order.
	 *
	 * Table existence is checked against the database's own catalog, never against
	 * a caller-supplied fragment of SQL (ADR-0003); the caller sends only names.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $tables The requested table names.
	 * @return array<int, string> The unknown table names, or empty when every one exists.
	 */
	private function missing_tables( array $tables ): array {

		// Skip the catalog query entirely when no table is requested.
		if ( $tables === [] ) {
			return [];
		}

		/**
		 * The WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Compare each requested name against the site's actual tables; every one
		// absent from the catalog is named in the 404, not merely the first.
		$existing = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the site's table catalog is the authoritative existence check (ADR-0003); a schema listing has nothing to prepare or cache.
		$missing = [];
		foreach ( $tables as $table ) {
			if ( ! in_array( $table, $existing, true ) ) {
				$missing[] = $table;
			}
		}

		return $missing;

	}

	/**
	 * Splits requested files into those inside the root, those that vanished, and
	 * those that are out of bounds.
	 *
	 * The boundary is never a sanitiser: a path is accepted only when it resolves
	 * to a real location at or under the installation root, and a traversal is
	 * rejected outright rather than rewritten (ADR-0003). A `..` segment attempts
	 * to leave the root even when `realpath` cannot resolve the target, so it is
	 * out of bounds rather than vanished. A path that does not resolve and never
	 * attempted to leave the root is vanished — gone between the manifest walk
	 * and this POST — and is what `strict: false` may skip. A null-byte path, a
	 * `..` segment, and a path that resolves outside the root are out of bounds
	 * and are never a skip. The root and each resolved path are compared on
	 * `wp_normalize_path`'d separators so the boundary holds on Windows/IIS too.
	 * When the root itself cannot be resolved — a broken install — the request
	 * fails closed, treating every file as out of bounds.
	 *
	 * @since 0.6.0
	 *
	 * @param array<int, string> $files The requested installation-root-relative file paths.
	 * @return array{kept: array<int, string>, vanished: array<int, string>, out_of_bounds: array<int, string>}
	 */
	private function classify_files( array $files ): array {

		// Nothing to check when no file is requested.
		if ( $files === [] ) {
			return [
				'kept' => [],
				'vanished' => [],
				'out_of_bounds' => [],
			];
		}

		// Fail closed if the root cannot be canonicalised: without a trusted root
		// there is no boundary to test against, so reject the whole selection. Its
		// separators are normalised so the boundary comparison below holds on Windows
		// too, where realpath yields backslashes a forward-slash needle would never match.
		$root = realpath( ABSPATH );
		if ( $root === false ) {
			return [
				'kept' => [],
				'vanished' => [],
				'out_of_bounds' => $files,
			];
		}
		$root = wp_normalize_path( $root );

		$kept = [];
		$vanished = [];
		$out_of_bounds = [];

		// Sort every requested path: inside the root, gone, or hostile.
		foreach ( $files as $file ) {

			// A null byte can never belong to a real path and would make realpath raise
			// a ValueError before authorization even runs; treat it as out of bounds so a
			// hostile path 404s like any other, never crashing the boundary.
			if ( str_contains( $file, "\0" ) ) {
				$out_of_bounds[] = $file;
				continue;
			}

			// A `..` segment is a traversal attempt even when the target does not
			// exist; realpath would then be false and must not become a skip.
			$segments = explode( '/', str_replace( '\\', '/', $file ) );
			if ( in_array( '..', $segments, true ) ) {
				$out_of_bounds[] = $file;
				continue;
			}

			// A false realpath inside the root is a vanished file; a resolved path is
			// kept only when it sits at or under the root on wp_normalize_path'd
			// separators.
			$resolved = realpath( $root . '/' . $file );
			if ( $resolved === false ) {
				$vanished[] = $file;
				continue;
			}
			$resolved = wp_normalize_path( $resolved );
			if ( $resolved !== $root && ! str_starts_with( $resolved, $root . '/' ) ) {
				$out_of_bounds[] = $file;
				continue;
			}

			$kept[] = $file;

		}

		return [
			'kept' => $kept,
			'vanished' => $vanished,
			'out_of_bounds' => $out_of_bounds,
		];

	}

	/**
	 * Builds a REST error carrying an explicit HTTP status.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $status  HTTP status the error maps to.
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable, translated message.
	 * @return WP_Error
	 */
	private function error( int $status, string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}

}
