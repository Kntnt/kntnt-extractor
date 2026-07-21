<?php
/**
 * REST controller for the authorized table-list endpoint.
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
 * Registers and answers `GET /kntnt-extractor/v1/tables`.
 *
 * Returns the Table list — the enumeration of tables in the site's database,
 * the file manifest's counterpart (ADR-0003) — each with a row count and a size
 * estimate so a caller can plan an extraction. The endpoint carries no
 * caller-supplied resource, so it needs no input validation: authorization is
 * the plugin's single two-capability gate, applied through {@see Authorizer}
 * (ADR-0002).
 *
 * @since 0.1.0
 */
final class Tables_Controller {

	/**
	 * The two-capability gate every listing and extraction endpoint shares.
	 *
	 * @since 0.1.0
	 *
	 * @param Authorizer $authorizer The both-capabilities authorization seam.
	 */
	public function __construct( private readonly Authorizer $authorizer ) {}

	/**
	 * Registers the tables route. Hooked on `rest_api_init`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			Status_Controller::REST_NAMESPACE,
			'/tables',
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => $this->get_tables( ... ),
				'permission_callback' => $this->authorizer->authorize( ... ),
			],
		);

	}

	/**
	 * Returns the Table list to an authorized caller.
	 *
	 * Each descriptor is `{ name, rows, bytes }`: the table name, a row-count
	 * estimate, and an estimated size in bytes — both figures drawn from one
	 * non-scanning `SHOW TABLE STATUS` snapshot so enumerating a large install
	 * stays O(number of tables) and cannot time out on a per-table COUNT(*) scan.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response The table list, as `{ "tables": [ … ] }`.
	 */
	public function get_tables(): WP_REST_Response {

		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Row and size estimates, keyed by table name, from one status snapshot.
		$stats = $this->table_stats();

		// Build one descriptor per table from the database's own catalogue of
		// names. No caller-supplied name reaches this endpoint (ADR-0003).
		$tables = [];
		foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			// get_col is typed as returning mixed values; a table name is a string.
			if ( ! is_string( $name ) ) {
				continue;
			}

			// Describe the table: a row-count estimate plus the size estimate.
			$tables[] = [
				'name' => $name,
				'rows' => $this->row_count( $name, $stats[ $name ]['rows'] ?? 0 ),
				'bytes' => $stats[ $name ]['bytes'] ?? 0,
			];

		}

		return new WP_REST_Response( [ 'tables' => $tables ] );

	}

	/**
	 * Resolves a table's row count, preferring the non-scanning estimate.
	 *
	 * `SHOW TABLE STATUS` already reports a `Rows` estimate for every table in a
	 * single query, so a populated table on a large InnoDB install needs no
	 * per-table scan — the estimate is returned as-is, which AC5 permits. Only a
	 * zero estimate falls back to an exact `COUNT(*)`: a table the engine
	 * believes empty counts instantly, and the fallback also recovers the true
	 * count on a backend that cannot estimate — the SQLite test backend flattens
	 * `Rows` to 0 for every table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name     Table name from the DB catalogue, never a caller.
	 * @param int    $estimate The engine's `Rows` estimate for the table.
	 * @return int The estimate when positive, otherwise an exact `COUNT(*)`.
	 */
	private function row_count( string $name, int $estimate ): int {

		// Trust a positive estimate: it costs no scan, and AC5 permits an estimate.
		if ( $estimate > 0 ) {
			return $estimate;
		}

		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// A zero estimate means an empty table (instant to count) or a backend
		// that cannot estimate; either way an exact COUNT(*) is cheap and correct.
		// The name comes from SHOW TABLES, never a caller, so interpolating it is
		// safe — an identifier cannot be bound through prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return $this->to_int( $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" ) );

	}

	/**
	 * Reads a row-count and size estimate for every table, keyed by table name.
	 *
	 * A single `SHOW TABLE STATUS` reports both `Rows` and
	 * `Data_length + Index_length` — non-locking MySQL estimates that need no
	 * per-table scan. The SQLite test backend answers the query but flattens
	 * every numeric column to 0, so a zero there is expected, not a bug; the
	 * caller's fallback turns that zero into an exact count.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{rows:int, bytes:int}> Table name to its
	 *         estimated row count and size in bytes.
	 */
	private function table_stats(): array {

		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Fold the status rows into a name -> { rows, bytes } map, tolerating a
		// backend that omits the numeric columns.
		$stats = [];
		foreach ( (array) $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ) as $status ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			// Each row is an associative array; index it only by a real table name.
			if ( ! is_array( $status ) ) {
				continue;
			}
			$name = $status['Name'] ?? null;
			if ( is_string( $name ) ) {
				$stats[ $name ] = [
					'rows' => $this->to_int( $status['Rows'] ?? 0 ),
					'bytes' => $this->to_int( $status['Data_length'] ?? 0 ) + $this->to_int( $status['Index_length'] ?? 0 ),
				];
			}

		}

		return $stats;

	}

	/**
	 * Coerces a raw database value to a non-negative integer.
	 *
	 * `$wpdb` reads are typed as `mixed` and numeric columns arrive as numeric
	 * strings; anything non-numeric collapses to 0 rather than a misleading cast.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value A value read from the database.
	 * @return int The value as an integer, or 0 when it is not numeric.
	 */
	private function to_int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

}
