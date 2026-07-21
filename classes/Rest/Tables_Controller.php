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
 * with an exact row count and a byte-size estimate. The plugin reports what
 * exists and categorises nothing — the caller decides what any table is for
 * (ADR-0003). Access is gated by the shared Authorizer, so only a caller
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
	 * endpoint takes no input to validate. Row counts are exact (`COUNT(*)`); the
	 * byte size is the engine's own `Data_length + Index_length` from
	 * `SHOW TABLE STATUS`, an approximation by nature.
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

		// Map each table to its byte-size estimate in one round trip; a schema
		// query has nothing to prepare and must not be cached. The row set is a
		// deserialization boundary — loosely typed — so each row and its values
		// are checked before use.
		$sizes = [];
		foreach ( (array) $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ) as $status ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $status ) ) {
				continue;
			}
			$name = $status['Name'] ?? null;
			if ( is_string( $name ) ) {
				$sizes[ $name ] = $this->to_int( $status['Data_length'] ?? null ) + $this->to_int( $status['Index_length'] ?? null );
			}
		}

		// Enumerate the site's tables and attach an exact row count and the size
		// estimate. A table name is a trusted catalog identifier, never caller
		// input, so it is back-tick quoted (doubling any embedded back-tick)
		// rather than prepared, which cannot bind an identifier.
		$tables = [];
		foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_string( $name ) ) {
				continue;
			}
			$quoted = '`' . str_replace( '`', '``', $name ) . '`';
			$tables[] = [
				'name' => $name,
				'rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$quoted}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
				'bytes' => $sizes[ $name ] ?? 0,
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
