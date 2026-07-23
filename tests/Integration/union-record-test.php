<?php
/**
 * Integration test: the shared release record for the #15 + #16 + #17 union.
 *
 * Each ticket's own diff is internally consistent, so a per-issue review cannot
 * see these two cross-issue drifts. They live only where the three landings meet
 * the one shared release record, and this file pins both:
 *
 *  - Finding 1 (changelog completeness): the trio's release section must record
 *    all three caller-visible landings — structure-only extraction (#16),
 *    `GET /environment` (#15), and `GET /extractions` listing (#17) — and must
 *    attribute the `api_version` 2 bump to the coordinated trio, not to #16
 *    alone. The Status_Controller docblock already frames the trio; the changelog
 *    must not contradict it. The record lived under `[Unreleased]` while the trio
 *    was in flight; the 0.2.0 release moved it into the `[0.2.0]` section, so that
 *    is where this check now reads it.
 *  - Finding 2 (@since coherence): all three features ship in the same next
 *    release, so they must carry one introduction version. #17 stamped the
 *    already-released 0.1.1 while #15/#16 stamped 0.2.0; the union must speak with
 *    one voice — 0.2.0 — in both the `list_jobs()` docblock and the #17 test file.
 *
 * @package Kntnt\Extractor
 * @since   0.2.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Rest\Extractions_Controller;

// Resolve the plugin root from this file's location so the checks are independent
// of where the harness mounts the plugin.
$plugin_root = dirname( __DIR__, 2 );

// Isolate the changelog's [0.2.0] section — the release the #15/#16/#17 trio
// shipped in. (The record lived under [Unreleased] until the 0.2.0 release moved
// it here, where it stays as the shipped, immutable record of that trio.)
$changelog = (string) file_get_contents( $plugin_root . '/CHANGELOG.md' );
$release_record = '';
if ( preg_match( '/##\s*\[0\.2\.0\](.*?)(?=\n##\s*\[)/s', $changelog, $match ) ) {
	$release_record = $match[1];
}

// Finding 1: the [0.2.0] section names all three landings' issues and both new
// endpoints, so a caller reading it learns the whole of what api_version 2 is.
kntnt_extractor_assert(
	str_contains( $release_record, '#15' ) && str_contains( $release_record, '#16' ) && str_contains( $release_record, '#17' ),
	'CHANGELOG [0.2.0] cites all three union issues #15/#16/#17'
);
kntnt_extractor_assert(
	str_contains( $release_record, '/environment' ) && str_contains( $release_record, '/extractions' ),
	'CHANGELOG [0.2.0] documents both new endpoints GET /environment (#15) and GET /extractions (#17)'
);

// Finding 1: the version bump is attributed to the trio, not to structure-only
// alone — the entry must mention `2` and reach beyond the single #16 change.
kntnt_extractor_assert(
	preg_match( '/version to `?2`?/', $release_record ) === 1 && str_contains( $release_record, '#15' ) && str_contains( $release_record, '#17' ),
	'CHANGELOG attributes the api_version 2 bump to the coordinated #15/#16/#17 trio'
);

// Finding 2: the #17 endpoint's introduction version matches its siblings — the
// list_jobs() docblock stamps the shared 0.2.0, never the already-released 0.1.1.
$doc = (string) ( new ReflectionMethod( Extractions_Controller::class, 'list_jobs' ) )->getDocComment();
kntnt_extractor_assert(
	str_contains( $doc, '@since 0.2.0' ) && ! str_contains( $doc, '0.1.1' ),
	'Extractions_Controller::list_jobs() is stamped @since 0.2.0 (issue #17)'
);

// Finding 2: the #17 test file carries the same shared introduction version.
$list_test = (string) file_get_contents( __DIR__ . '/extractions-list-test.php' );
kntnt_extractor_assert(
	str_contains( $list_test, '@since   0.2.0' ) && ! str_contains( $list_test, '0.1.1' ),
	'extractions-list-test.php is stamped @since 0.2.0 (issue #17)'
);
