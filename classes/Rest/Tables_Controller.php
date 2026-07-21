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
 * Returns the Table list: every table that exists in the site's database, each
 * with an estimated row count and a byte-size estimate — both the storage
 * engine's own cached statistics, read without scanning table data. The plugin
 * reports what exists and categorises nothing — the caller decides what any
 * table is for (ADR-0003). Access is gated by the shared Authorizer, so only a caller
 * holding both the Operate capability and `manage_options` reaches the data;
 * everyone else is refused with 403.
 *
 * @since 0.1.0
 */
final class Tables_Controller {

	/**
	 * Wires the controller to the shared authorization gate.
	 *
	 * @since 0.1.0
	 *
	 * @param Authorizer $authorizer The shared both-capabilities access gate.
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
	 * Returns the Table list with a row count and byte-size estimate per table.
	 *
	 * Table names come from the database catalog, never from the caller, so the
	 * endpoint takes no input to validate. Both figures are the storage engine's
	 * own cached statistics from a single `SHOW TABLE STATUS`: the row count is
	 * its approximate `Rows`, the byte size its `Data_length + Index_length`. Both
	 * are estimates by nature (AC5), and are read from table metadata rather than
	 * by scanning each table — so a large install's `postmeta`, `options`, or
	 * action-scheduler logs are never counted row by row, and the endpoint stays
	 * O(number of tables) instead of risking an execution-time or HTTP timeout.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response The listing, as `{ "tables": [ { name, rows, bytes } ] }`.
	 */
	public function get_tables(): WP_REST_Response {

		/**
		 * The WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Read every table's engine-level statistics in one round trip, keyed by
		// name: the approximate row count and the byte-size estimate. A schema
		// query has nothing to prepare and must not be cached. The row set is a
		// deserialization boundary — loosely typed — so each row and its values
		// are checked before use.
		$stats = [];
		foreach ( (array) $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ) as $status ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $status ) ) {
				continue;
			}
			$name = $status['Name'] ?? null;
			if ( is_string( $name ) ) {
				$stats[ $name ] = [
					'rows' => $this->to_int( $status['Rows'] ?? null ),
					'bytes' => $this->to_int( $status['Data_length'] ?? null ) + $this->to_int( $status['Index_length'] ?? null ),
				];
			}
		}

		// Enumerate the site's tables and attach each one's estimated row count
		// and byte size. `SHOW TABLES` is the authoritative catalog of what exists
		// (ADR-0003); a table absent from the statistics above — one just created,
		// with no gathered stats — reports zero rather than being dropped.
		$tables = [];
		foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_string( $name ) ) {
				continue;
			}
			$tables[] = [
				'name' => $name,
				'rows' => $stats[ $name ]['rows'] ?? 0,
				'bytes' => $stats[ $name ]['bytes'] ?? 0,
			];
		}

		return new WP_REST_Response( [ 'tables' => $tables ] );

	}

	/**
	 * Coerces a loosely typed database column value into an integer.
	 *
	 * The schema row set types every column as `mixed`; a numeric value becomes
	 * its integer, anything else (a null column, a view without stats) becomes 0.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value A single column value from a schema query.
	 * @return int The value as an integer, or 0 when it is not numeric.
	 */
	private function to_int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

}
