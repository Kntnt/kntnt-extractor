<?php
/**
 * REST controller for the unauthenticated status endpoint.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Rest;

use Kntnt\Extractor\Authorizer;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers and answers `GET /kntnt-extractor/v1/status`.
 *
 * The status endpoint is deliberately public and unauthenticated: it lets a
 * caller read the REST contract's API version and decide whether it can drive
 * this installation before attempting anything that needs credentials. The
 * response carries only the API version, never the plugin's release version —
 * the two are distinct by design (see docs/adr/0005 and CONTEXT.md).
 *
 * It is also the endpoint that answers "who am I here?". Credentials are
 * optional but not ignored: when they arrive and resolve to a WordPress user,
 * the response additionally reports that user's login and which of the two
 * composing capabilities it holds, turning a diagnosis that used to be inferred
 * from the pattern of errors across other endpoints into a direct answer
 * (ADR-0012). The unauthenticated handshake is unchanged and stays reachable
 * without credentials, since every client depends on it.
 *
 * A third, distinct question — "what does this build actually do?" — is
 * answered the same way, and only there: an authenticated caller additionally
 * receives `honours`, the list of caller-visible behaviours this build
 * implements. `api_version` cannot answer that question, because a behaviour
 * can ship additively without moving it (ADR-0005, ADR-0017); this list exists
 * because inferring behaviour from the version has already failed once.
 *
 * @since 0.1.0
 */
final class Status_Controller {

	/**
	 * The REST namespace this plugin owns, including its contract version.
	 *
	 * @since 0.1.0
	 */
	public const string REST_NAMESPACE = 'kntnt-extractor/v1';

	/**
	 * The REST contract's own version, distinct from the plugin release version.
	 *
	 * Increments only when caller-visible behaviour changes, including a purely
	 * behavioural change with no signature change; a bug fix that leaves the
	 * contract as callers already understood it does not bump it (ADR-0005).
	 *
	 * Public because it is the single source of truth for the contract version: the
	 * audit log stamps the same value into every record it writes (ADR-0006).
	 *
	 * Raised to 2 for the structure-only cutover trio (issues #15/#16/#17): the
	 * `tables_structure_only` request field and the structure-only segments it seals
	 * are a caller-visible change to the extraction contract (ADR-0005). The sibling
	 * tickets ship under this one coordinated bump rather than one bump each.
	 *
	 * Raised to 3 for the restricted-path deny-list (issue #21, ADR-0011):
	 * `POST /extractions` now rejects a selection naming a credential-bearing path
	 * with a new `kntnt_extractor_restricted_path` 422, a caller-visible behaviour
	 * change even though no request field changed shape.
	 *
	 * Raised to 4 for the identity-diagnosis change (ADR-0012): this endpoint now
	 * reports the authenticated caller's login and capabilities alongside the
	 * version, and the shared authorization gate answers 401
	 * `kntnt_extractor_not_authenticated` or a 403 naming the missing capability in
	 * place of the single `kntnt_extractor_forbidden` it used to return. Both are
	 * caller-visible and ship under one coordinated bump.
	 *
	 * Raised to 5 for chunked table dumping (ADR-0013): a table now appears in the
	 * sealed index once per slice rather than once, so a reader must concatenate every
	 * segment carrying a name — the rule file parts already required — rather than
	 * expecting one segment per table. The artifact's shape is part of the contract.
	 *
	 * Raised to 6 for the poll contract's fifth progress counter, `chunks_done`: the
	 * four table/file counters advance only when a whole resource finishes, so a job
	 * working steadily through one large table reports exactly what a wedged job
	 * reports, and a client cannot tell slow from stuck. The new counter moves on every
	 * packaging chunk. It is additive — every existing field keeps its meaning — but
	 * the poll response is caller-visible, so it ships under a bump like any other
	 * change to it.
	 *
	 * Raised to 7 for the `GET /environment` define-disclosure allow-list
	 * (ADR-0018): which `wp-config` defines report a value, rather than the
	 * previous deny-list's six, is now governed by a curated allow-list plus a
	 * heuristic backstop, and every `defines` record gains a `disclosure`
	 * member. Read against [0017](../../docs/adr/0017-api-version-bounds-the-artifact-contract-honours-reports-what-a-build-does.md),
	 * this is not a change of artifact shape and would ordinarily be a `honours`
	 * entry instead. It bumps anyway, as a deliberate compatibility interlock
	 * ADR-0018 argues for explicitly: the consuming client currently classifies a
	 * `null`-valued define by name alone and ports an unrecognised one into the
	 * local `wp-config.php` as `define('X', null)`, which then reports
	 * `defined('X') === true` and silently defeats that plugin's own fallback.
	 * Against the wider allow-list, defines that were never null before now are,
	 * and the client's exclusion list does not yet know to treat that as
	 * withheld rather than legitimate. `honours` only ever adds a name an old
	 * client is free to keep ignoring; this changes what null already means to
	 * logic the client has already shipped, without asking it first — exactly
	 * what the verified ceiling exists to gate.
	 *
	 * @since 0.1.0
	 */
	public const int API_VERSION = 7;

	/**
	 * Caller-visible behaviours a build may or may not honour, reported to an
	 * authenticated caller as `honours` so it can gate on a name instead of
	 * inferring the answer from `api_version` (docs/adr/0017).
	 *
	 * Every entry here shipped without moving {@see API_VERSION}: each is additive,
	 * and an old client that has never heard of the name is unaffected by its
	 * presence. `strict` is the one that forced this list to exist — `POST
	 * /extractions` has accepted it since before this build, and no version number
	 * distinguishes a build that honours it from one that does not. `disclosure`
	 * is the one exception to "additive": the per-record member `GET /environment`
	 * always carries (ADR-0018) is new surface a client can check for before
	 * depending on it, exactly like every other name here, even though the
	 * allow-list it reports on shipped under a coordinated {@see API_VERSION}
	 * bump rather than silently. `state` is `GET /extractions`' optional query
	 * parameter that additionally admits the caller's own terminal jobs
	 * (ADR-0019): additive by the same reasoning as `strict`, since the parameter
	 * is absent-by-default and every existing client's unparameterised request is
	 * unaffected by its existence. `unknown_resource_names` is `POST /extractions`
	 * naming every offender in `data.tables` and `data.files` on a
	 * `404 kntnt_extractor_unknown_resource` rather than refusing without saying
	 * what was missing: additive `data` members on an error an old client already
	 * handles, and the one behaviour on this list a caller must currently infer
	 * from `api_version` to know whether the names will be there — which is the
	 * inference ADR-0017 exists to remove. `selection_limits` is the name a caller
	 * checks for before assuming an arbitrarily large selection or an arbitrarily
	 * large body will be accepted: a build carrying it refuses either with
	 * `422 kntnt_extractor_selection_too_large` or `413
	 * kntnt_extractor_payload_too_large` rather than working through it (ADR-0020).
	 * It is presence-only like every other name here, so the limits themselves
	 * travel on the refusal instead — each error's `data` carries the `limit` it was
	 * checked against and the caller's own `count` or `bytes` — which is where a
	 * client needs the number anyway: at the moment it has to split a request, not
	 * in advance of ever sending one. `chunk_size` is `POST /extractions`' optional
	 * per-run file-part budget: additive by the same reasoning as `strict`, since an
	 * omitted member packages at the site's own Config default exactly as before —
	 * and worth naming precisely because the value a host survives is measured
	 * rather than universal (`docs/measurements/2026-08-19-chunk-size-curve.md`), so
	 * a client that cannot see the name is stuck with whatever constant the site
	 * happens to carry. `timings` is `GET /extractions/{id}`' bounded per-phase
	 * chunk timings (issue #39), and it is the one name here that reports a
	 * capability rather than an unconditional behaviour: the member appears only
	 * once the site's own `phase_timing` knob has asked for the measurement, which
	 * it does not by default. That is deliberate and is the same shape as
	 * `chunk_size`, whose presence likewise says the build understands the member
	 * rather than that any particular value is in force — a client checks the name
	 * to know whether asking is even possible, and reads the poll to know whether
	 * anyone asked.
	 *
	 * @since 0.6.0
	 */
	private const array HONOURED_BEHAVIOURS = [
		'attempts',
		'chunk_size',
		'chunks_done',
		'disclosure',
		'selection_limits',
		'skipped_files',
		'state',
		'strict',
		'timings',
		'unknown_resource_names',
	];

	/**
	 * Registers the status route. Hooked on `rest_api_init`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => $this->get_status( ... ),
				'permission_callback' => '__return_true',
			],
		);

	}

	/**
	 * Returns the API version, plus the caller's identity and honoured
	 * behaviours when there is one.
	 *
	 * An anonymous caller receives exactly the historical handshake and nothing
	 * more — `{ "api_version": <int> }` — so the contract every existing client
	 * reads is untouched. A request whose credentials resolved to a WordPress user
	 * receives three further members: `authenticated_as`, the user's
	 * `user_login`; `capabilities`, a map of each of the plugin's two composing
	 * capabilities to whether that user holds it; and `honours`, the sorted list
	 * of caller-visible behaviour names this build implements (docs/adr/0017).
	 * The identity pair answers "who am I here?" (ADR-0012); `honours` answers
	 * the separate question "what does this build do?", which no version number
	 * can — a caller meeting a refusal elsewhere can tell "my credentials never
	 * arrived" from "they arrived as somebody who may not do this" without
	 * probing other endpoints, and a caller planning a request can tell whether
	 * a behaviour it wants exists without probing that endpoint either.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response The API version, and the caller's login,
	 *                          capabilities, and honoured behaviours when a user
	 *                          is authenticated.
	 */
	public function get_status(): WP_REST_Response {

		// The version handshake is the entire contract for an anonymous caller. It
		// answers "may I proceed with this artifact contract at all", and that
		// question must be answerable before any credential resolves, so it stays
		// outside the gate below (docs/adr/0017).
		$status = [ 'api_version' => self::API_VERSION ];

		// Answer the identity question, and — a distinct question with a distinct
		// answer — what this build actually does, for an authenticated caller only.
		// `honours` is a build fingerprint, not part of the version-refusal
		// handshake, so it is disclosed only once credentials have resolved.
		if ( is_user_logged_in() ) {
			$status['authenticated_as'] = wp_get_current_user()->user_login;
			$status['capabilities'] = [
				Authorizer::OPERATE_CAPABILITY => current_user_can( Authorizer::OPERATE_CAPABILITY ),
				Authorizer::MANAGE_CAPABILITY => current_user_can( Authorizer::MANAGE_CAPABILITY ),
			];
			$status['honours'] = self::HONOURED_BEHAVIOURS;
		}

		return new WP_REST_Response( $status );

	}

}
