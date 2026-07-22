<?php
/**
 * Serialises a database table into mysqldump-compatible SQL through $wpdb.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use RuntimeException;

/**
 * Dumps one table to a self-contained block of `mysqldump`-compatible SQL.
 *
 * The extraction runs in pure PHP with no external binary (ADR-0007), so the dump
 * that `mysqldump` would produce is instead generated here through `$wpdb`: the
 * table's `CREATE TABLE` definition, a `DROP TABLE IF EXISTS` ahead of it, and one
 * or more extended `INSERT` statements carrying every row. A decrypted artifact
 * therefore restores with ordinary tools, exactly as ADR-0009 promises.
 *
 * The dumped SQL is a caller-visible part of the artifact and so is bound to the
 * API version. Every value is emitted as an escaped string literal (or `NULL`),
 * never re-typed: quoting a numeric column as a string round-trips losslessly on
 * MySQL, whereas guessing which string is "really" a number risks turning a
 * zero-padded identifier like `0123` into `123`.
 *
 * The table name is never a caller-supplied fragment of SQL. It is validated
 * against the live catalog before it is interpolated into any statement, so a job
 * record tampered with after its create-time validation cannot smuggle SQL through
 * the one place an identifier — which cannot be a bound parameter — reaches a query.
 *
 * @since 0.1.0
 */
final class Table_Dumper {

	/**
	 * Rows emitted per extended `INSERT` statement.
	 *
	 * Batching keeps the dump close to `mysqldump --extended-insert` while bounding
	 * how long any single statement grows on a wide or busy table.
	 *
	 * @since 0.1.0
	 */
	private const int ROWS_PER_INSERT = 100;

	/**
	 * Returns the full `mysqldump`-compatible SQL for one table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table The table to dump; must be an existing table name.
	 * @return string The table's structure and data as SQL.
	 *
	 * @throws RuntimeException When the table is not in the live catalog or its
	 *                          definition cannot be read.
	 */
	public function dump( string $table ): string {

		// Refuse anything not in the live catalog before the name reaches a query,
		// then assemble the structure block above the data block, the order a
		// mysqldump reload depends on.
		$this->require_known_table( $table );

		return $this->structure_sql( $table ) . $this->data_sql( $table );

	}

	/**
	 * Returns only the structure block for one table — DDL, no rows.
	 *
	 * The same `--` header, `DROP TABLE IF EXISTS`, and `CREATE TABLE` that {@see dump()}
	 * emits, but without any data block: a structure-only table (issue #16) carries its
	 * schema into the artifact so a reload recreates it, yet ships none of its rows —
	 * exactly what lets an operational table (analytics, cookie-consent, search-index)
	 * be created empty rather than transferred whole. An empty `CREATE` still reloads
	 * idempotently, so the recreated table is valid and ready to receive its own data.
	 *
	 * @since 0.2.0
	 *
	 * @param string $table The table to dump the structure of; must be an existing table name.
	 * @return string The table's `DROP`/`CREATE` DDL as SQL, with no `INSERT`.
	 *
	 * @throws RuntimeException When the table is not in the live catalog or its
	 *                          definition cannot be read.
	 */
	public function dump_structure( string $table ): string {

		// Refuse anything not in the live catalog before the name reaches a query, then
		// return the structure block alone — no data block, so no rows are dumped.
		$this->require_known_table( $table );

		return $this->structure_sql( $table );

	}

	/**
	 * Rejects a table name that is not an existing table, closing the identifier hole.
	 *
	 * A table name cannot be a bound parameter, so it is interpolated directly into
	 * `SHOW CREATE TABLE` and the row query below. Validating it against the site's
	 * own catalog — never against caller-supplied SQL (ADR-0003) — is what keeps
	 * that interpolation safe even if the job record was altered after it was created.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table The table name to validate.
	 * @return void
	 *
	 * @throws RuntimeException When the name is not an existing table.
	 */
	private function require_known_table( string $table ): void {

		/**
		 * The WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// The catalog is the authoritative allow-list; a name absent from it never
		// reaches a query.
		$existing = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a schema listing is the authoritative existence check (ADR-0003); nothing to prepare or cache.
		if ( ! in_array( $table, $existing, true ) ) {
			throw new RuntimeException( 'Refusing to dump a table that does not exist in the catalog.' );
		}

	}

	/**
	 * Builds the structure block: a header, a `DROP TABLE`, and the `CREATE TABLE`.
	 *
	 * The definition comes verbatim from `SHOW CREATE TABLE`, so it is the engine's
	 * own canonical DDL rather than one this code reconstructs and could drift from.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table The validated table name.
	 * @return string The structure SQL, ending in a trailing newline.
	 *
	 * @throws RuntimeException When the table's definition cannot be read.
	 */
	private function structure_sql( string $table ): string {

		/**
		 * The WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Read the engine's own CREATE statement; its absence means the table went
		// away between the catalog check and here, which fails the dump rather than
		// producing a structureless artifact.
		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW CREATE TABLE reads DDL, it changes nothing; identifier validated against the catalog and cannot be bound.
		$create = is_array( $row ) && isset( $row[1] ) && is_string( $row[1] ) ? $row[1] : null;
		if ( $create === null ) {
			throw new RuntimeException( 'Unable to read the table definition to dump.' );
		}

		// Frame the definition the way mysqldump does: a comment header, a drop that
		// makes a reload idempotent, then the canonical CREATE.
		return "--\n-- Table structure for table `{$table}`\n--\n\n"
			. "DROP TABLE IF EXISTS `{$table}`;\n"
			. rtrim( $create, ";\n" ) . ";\n";

	}

	/**
	 * Builds the data block: a header and extended `INSERT` statements for every row.
	 *
	 * An empty table yields only the header, exactly as mysqldump omits an `INSERT`
	 * with no rows to carry.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table The validated table name.
	 * @return string The data SQL, ending in a trailing newline.
	 */
	private function data_sql( string $table ): string {

		/**
		 * The WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Pull every row as an associative array so column order follows the table's
		// own definition, the order the column-less INSERT below relies on.
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier validated against the catalog; identifiers cannot be bound.
		$header = "\n--\n-- Dumping data for table `{$table}`\n--\n\n";
		if ( ! is_array( $rows ) || $rows === [] ) {
			return $header;
		}

		// Emit each batch of rows as one extended INSERT, close to
		// mysqldump --extended-insert, so a large table is not one unbounded statement.
		$sql = $header;
		foreach ( array_chunk( $rows, self::ROWS_PER_INSERT ) as $batch ) {
			$tuples = array_map( $this->row_tuple( ... ), $batch );
			$sql .= "INSERT INTO `{$table}` VALUES " . implode( ',', $tuples ) . ";\n";
		}

		return $sql;

	}

	/**
	 * Renders one row as a parenthesised, comma-separated tuple of SQL literals.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string, mixed> $row One result row from $wpdb, column => value.
	 * @return string The row as `(v1,v2,…)`.
	 */
	private function row_tuple( array $row ): string {

		return '(' . implode( ',', array_map( $this->literal( ... ), array_values( $row ) ) ) . ')';

	}

	/**
	 * Renders one column value as a SQL literal: `NULL`, or an escaped string.
	 *
	 * The escaping is MySQL's own string-literal escaping — backslash, single quote,
	 * NUL, newline, carriage return and Ctrl-Z — so any byte a column holds survives
	 * a reload intact. The value arrives from `$wpdb` typed as `mixed`; it is a string
	 * or null in practice, so it is coerced at this boundary rather than trusted blind.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The raw column value from $wpdb: a string, or null.
	 * @return string The value as a SQL literal.
	 */
	private function literal( mixed $value ): string {

		// A genuine NULL is the one unquoted literal; every other value renders as a
		// quoted, escaped string that round-trips losslessly regardless of column type.
		if ( $value === null ) {
			return 'NULL';
		}
		$escaped = strtr(
			is_scalar( $value ) ? (string) $value : '',
			[
				'\\' => '\\\\',
				"'" => "\\'",
				"\0" => "\\0",
				"\n" => "\\n",
				"\r" => "\\r",
				"\x1a" => '\\Z',
			],
		);

		return "'{$escaped}'";

	}

}
