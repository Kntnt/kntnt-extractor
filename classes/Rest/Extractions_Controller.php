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
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers and answers `POST /extractions`, `GET /extractions/{id}`,
 * `POST /extractions/{id}/consume`, `DELETE /extractions/{id}`, and the internal
 * `POST /extractions/{id}/tick`.
 *
 * `POST /extractions` turns an already-resolved selection of tables and/or files,
 * plus the caller's ephemeral X25519 public key, into a queued Extraction job
 * bound to the caller (ADR-0004) and fires the first loopback that starts its
 * execution. `GET /extractions/{id}` reports that job's state to its owner, hands
 * back the sealed artifact's download link once the job is ready, and
 * opportunistically nudges a stalled queue. `POST /extractions/{id}/tick` is the
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
 * a 404.
 *
 * The order the create request is validated in is a security property, not an
 * incidental one (ADR-0003): a malformed body is a 422, an absent or malformed
 * key a 400, and an unknown table or a file resolving outside the installation
 * root a 404 — and that 404 is decided BEFORE the capability gate, so the plugin
 * rejects a request for something that does not exist without first disclosing
 * whether the caller could have been authorized. Only once existence holds does
 * the shared both-capabilities Authorizer get to refuse an unauthorized caller
 * with 403. The out-of-root check is a `realpath` boundary, never a sanitiser:
 * a traversal path is rejected outright, not rewritten into a safe one.
 *
 * @since 0.1.0
 */
final class Extractions_Controller {

	/**
	 * Non-terminal jobs allowed at once when the knob does not override it.
	 *
	 * One global job by default (ADR-0004): an extraction is heavy, and a second
	 * concurrent one is refused with 429. The ceiling is resolved through the
	 * Config seam under the knob `max_active_jobs`, so a site raises it with the
	 * `KNTNT_EXTRACTOR_MAX_ACTIVE_JOBS` constant or the matching filter.
	 *
	 * @since 0.1.0
	 */
	private const int DEFAULT_MAX_ACTIVE_JOBS = 1;

	/**
	 * Wires the controller to the access gate, the Config seam, and the job store.
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
	 * Registers both extraction routes. Hooked on `rest_api_init`.
	 *
	 * The create route's permission callback runs the whole existence-and-key
	 * validation before the capability check, which is what lets a 404 or 400
	 * precede the 403 (ADR-0003). The id-addressed routes capture a 32-hex id
	 * straight from the path, so a malformed id never matches and never reaches the
	 * store. Poll and cancel share one route path — a `GET` reads the job, a `DELETE`
	 * cancels it — behind the same capability gate, with the per-job ownership binding
	 * layered on inside each callback.
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
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => $this->create( ... ),
				'permission_callback' => $this->can_create( ... ),
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
	 * The request is fully validated first — body shape (422), public key (400),
	 * and resource existence (404) — and only a request that survives all three
	 * reaches the capability gate. Running validation here rather than in the main
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
	 * validation of them.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming create request.
	 * @return WP_REST_Response|WP_Error A 201 with `{ id, state }`, or a 429 when the
	 *                                   concurrency ceiling is already reached.
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
		if ( $this->store->count_active() >= $this->max_active_jobs() ) {
			return new WP_Error(
				'kntnt_extractor_too_many_jobs',
				__( 'Another extraction is already in progress. Wait for it to finish before starting another.', 'kntnt-extractor' ),
				[ 'status' => 429 ],
			);
		}

		// Persist a queued job bound to the caller, then fire the initial loopback so
		// its execution begins without waiting for the first poll (ADR-0007).
		$job = $this->store->create( get_current_user_id(), $payload['public_key'], $payload['tables'], $payload['files'] );
		$this->dispatcher->maybe_nudge( $job );

		return new WP_REST_Response(
			[
				'id' => $job->id,
				'state' => $job->state->value,
			],
			201,
		);

	}

	/**
	 * Reports a job's state to its owner.
	 *
	 * An unknown id is a 404 and a job owned by someone else is a 403 — existence
	 * before ownership, mirroring the create path's existence-before-capability
	 * order. The capability gate has already admitted the caller through the
	 * route's permission callback, so this only adds the per-job ownership binding
	 * (AC4).
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming poll request, carrying the id.
	 * @return WP_REST_Response|WP_Error A 200 with `{ id, state }`, a 404 for an
	 *                                   unknown job, or a 403 for a non-owner.
	 */
	public function poll( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		// Resolve the caller's own job; an unknown id is a 404 and a non-owner a 403.
		$job = $this->resolve_owned_job( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		// Opportunistically restart a queued or stalled job's loopback, but never one
		// currently being ticked — the poll kicks the driver, it does not do the work
		// (ADR-0007). A ready job reports the download link its sealed artifact is
		// fetched through; a job not yet ready reports it as null.
		$this->dispatcher->maybe_nudge( $job );

		return new WP_REST_Response(
			[
				'id' => $job->id,
				'state' => $job->state->value,
				'download_url' => $this->store->download_url( $job ),
			],
		);

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
	 *                                   the job is not ready.
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

		// Delete the artifact and the working directory, then report the job consumed;
		// the ready-time audit record is a separate file this never touches (ADR-0006).
		$this->store->purge( $job );

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
	 *                                   unknown job, or a 403 for a non-owner.
	 */
	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		// Resolve the caller's own job; an unknown id is a 404 and a non-owner a 403.
		$job = $this->resolve_owned_job( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		// Delete the artifact and the working directory whatever state the job is in,
		// then report it cancelled; no ready transition occurs, so no audit is written.
		$this->store->purge( $job );

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
	 * The checks run in the contract's fixed precedence: a body that is not a JSON
	 * object, or a selection that is not a list of non-empty strings, or one that
	 * selects nothing, is a 422; an absent or malformed public key is a 400; an
	 * unknown table or a file resolving outside the installation root is a 404.
	 * Existence is deliberately the last of the three so a well-formed request is
	 * never told a resource is missing before it is told its own shape is wrong,
	 * yet still ahead of the capability gate its caller runs afterwards.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming create request.
	 * @return array{tables: array<int, string>, files: array<int, string>, public_key: string}|WP_Error
	 */
	private function validate_payload( WP_REST_Request $request ): array|WP_Error {

		// Parse the body; anything that is not a JSON object is a malformed body.
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) ) {
			return $this->error( 422, 'kntnt_extractor_malformed_body', __( 'The request body must be a JSON object.', 'kntnt-extractor' ) );
		}

		// Normalise both selections; a present-but-ill-typed selection, one holding
		// an empty entry, or a request that selects nothing at all is a malformed body.
		$tables = $this->string_selection( $data['tables'] ?? [] );
		$files = $this->string_selection( $data['files'] ?? [] );
		if ( $tables === null || $files === null || ( $tables === [] && $files === [] ) ) {
			return $this->error( 422, 'kntnt_extractor_malformed_body', __( 'Provide tables and/or files as arrays of non-empty strings, selecting at least one.', 'kntnt-extractor' ) );
		}

		// Require a well-formed key: present, valid base64, exactly a 32-byte X25519
		// public key. Its absence or malformation is a client error, not a not-found.
		$public_key = $this->canonical_public_key( $data['public_key'] ?? null );
		if ( $public_key === null ) {
			return $this->error( 400, 'kntnt_extractor_invalid_public_key', __( 'A valid base64-encoded 32-byte X25519 public key is required.', 'kntnt-extractor' ) );
		}

		// Existence-first: an unknown table or an out-of-root file is a 404, decided
		// before the capability gate (ADR-0003). Tables are checked before files.
		$missing = $this->first_missing_table( $tables ) ?? $this->first_out_of_root_file( $files );
		if ( $missing !== null ) {
			return $this->error( 404, 'kntnt_extractor_unknown_resource', __( 'A requested table or file does not exist within this installation.', 'kntnt-extractor' ) );
		}

		return [
			'tables' => $tables,
			'files' => $files,
			'public_key' => $public_key,
		];

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
	 * Returns the first requested table that does not exist, or null when all do.
	 *
	 * Table existence is checked against the database's own catalog, never against
	 * a caller-supplied fragment of SQL (ADR-0003); the caller sends only names.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $tables The requested table names.
	 * @return string|null The first unknown table name, or null when every one exists.
	 */
	private function first_missing_table( array $tables ): ?string {

		// Skip the catalog query entirely when no table is requested.
		if ( $tables === [] ) {
			return null;
		}

		/**
		 * The WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Compare each requested name against the site's actual tables; the first one
		// absent from the catalog is the unknown resource that triggers the 404.
		$existing = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the site's table catalog is the authoritative existence check (ADR-0003); a schema listing has nothing to prepare or cache.
		foreach ( $tables as $table ) {
			if ( ! in_array( $table, $existing, true ) ) {
				return $table;
			}
		}

		return null;

	}

	/**
	 * Returns the first requested file that resolves outside the root, or null.
	 *
	 * The boundary is a `realpath` check, never a sanitiser: a path is accepted only
	 * when it resolves to a real location at or under the installation root, and a
	 * traversal or absent path is rejected outright rather than rewritten (ADR-0003).
	 * The root and each resolved path are compared on `wp_normalize_path`'d separators
	 * so the boundary holds on Windows/IIS too, where `realpath` renders paths with
	 * backslashes a forward-slash prefix would never match — without that normalisation
	 * every valid in-root file would 404 there, disabling file selection on a platform
	 * the plugin explicitly supports. A path carrying a null byte is rejected here too:
	 * `realpath` would raise a ValueError on such input, so it counts as out of root
	 * rather than reaching that boundary. When the root itself cannot be resolved — a
	 * broken install — the request fails closed, treating every file as out of bounds.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $files The requested installation-root-relative file paths.
	 * @return string|null The first out-of-root or absent path, or null when all resolve inside.
	 */
	private function first_out_of_root_file( array $files ): ?string {

		// Nothing to check when no file is requested.
		if ( $files === [] ) {
			return null;
		}

		// Fail closed if the root cannot be canonicalised: without a trusted root
		// there is no boundary to test against, so reject the whole selection. Its
		// separators are normalised so the boundary comparison below holds on Windows
		// too, where realpath yields backslashes a forward-slash needle would never match.
		$root = realpath( ABSPATH );
		if ( $root === false ) {
			return reset( $files );
		}
		$root = wp_normalize_path( $root );

		// Check every requested path against the root, rejecting the first that does
		// not resolve to a real location at or under it — outright, never rewritten.
		foreach ( $files as $file ) {

			// A null byte can never belong to a real path and would make realpath raise
			// a ValueError before authorization even runs; treat it as out of root so a
			// hostile path 404s like any other, never crashing the boundary.
			if ( str_contains( $file, "\0" ) ) {
				return $file;
			}

			// A false realpath (no such file) is out of root outright; otherwise the
			// comparison runs on wp_normalize_path'd separators so a path at or under the
			// root is recognised on every platform, not only where realpath uses slashes.
			$resolved = realpath( $root . '/' . $file );
			if ( $resolved === false ) {
				return $file;
			}
			$resolved = wp_normalize_path( $resolved );
			if ( $resolved !== $root && ! str_starts_with( $resolved, $root . '/' ) ) {
				return $file;
			}

		}

		return null;

	}

	/**
	 * Resolves the concurrency ceiling through the Config seam, clamped to at least one.
	 *
	 * A non-numeric or non-positive override cannot disable creation outright; the
	 * floor of one keeps the endpoint usable however the knob is misconfigured.
	 *
	 * @since 0.1.0
	 *
	 * @return int The maximum number of non-terminal jobs allowed at once.
	 */
	private function max_active_jobs(): int {

		$configured = $this->config->get( 'max_active_jobs', self::DEFAULT_MAX_ACTIVE_JOBS );

		return max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_MAX_ACTIVE_JOBS );

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
