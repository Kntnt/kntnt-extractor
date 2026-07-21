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
	 * Each descriptor is `{ name, rows, bytes }`: the table name, an exact row
	 * count, and an estimated size in bytes.
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

		// Size estimates in bytes, keyed by table name.
		$sizes = $this->size_estimates();

		// Build one descriptor per table from the database's own catalogue of
		// names. No caller-supplied name reaches this endpoint (ADR-0003), so
		// interpolating a name into COUNT(*) is safe — an identifier cannot be
		// bound through prepare(), and this one never came from a caller.
		$tables = [];
		foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			// get_col is typed as returning mixed values; a table name is a string.
			if ( ! is_string( $name ) ) {
				continue;
			}

			// Describe the table: an exact row count plus the size estimate.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$rows = $this->to_int( $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" ) );
			$tables[] = [
				'name' => $name,
				'rows' => $rows,
				'bytes' => $sizes[ $name ] ?? 0,
			];

		}

		return new WP_REST_Response( [ 'tables' => $tables ] );

	}

	/**
	 * Reads a size estimate in bytes for every table, keyed by table name.
	 *
	 * `SHOW TABLE STATUS` reports `Data_length + Index_length` — a non-locking
	 * MySQL estimate. The SQLite test backend answers the query but reports the
	 * size columns as 0, so a zero there is expected, not a bug.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> Table name to estimated size in bytes.
	 */
	private function size_estimates(): array {

		/**
		 * WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Fold the status rows into a name -> bytes map, tolerating a backend that
		// omits the size columns.
		$sizes = [];
		foreach ( (array) $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ) as $status ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			// Each row is an associative array; index it only by a real table name.
			if ( ! is_array( $status ) ) {
				continue;
			}
			$name = $status['Name'] ?? null;
			if ( is_string( $name ) ) {
				$sizes[ $name ] = $this->to_int( $status['Data_length'] ?? 0 ) + $this->to_int( $status['Index_length'] ?? 0 );
			}

		}

		return $sizes;

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
