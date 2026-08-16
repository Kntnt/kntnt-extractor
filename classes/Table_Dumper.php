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
 * Dumps a table to `mysqldump`-compatible SQL, one bounded slice of rows at a time.
 *
 * The extraction runs in pure PHP with no external binary (ADR-0007), so the dump
 * that `mysqldump` would produce is instead generated here through `$wpdb`: the
 * table's `CREATE TABLE` definition, a `DROP TABLE IF EXISTS` ahead of it, and one
 * or more extended `INSERT` statements carrying every row. A decrypted artifact
 * therefore restores with ordinary tools, exactly as ADR-0009 promises.
 *
 * No call ever holds a whole table. {@see dump_chunk()} renders one bounded slice of
 * rows and hands back the cursor the next slice resumes after, so a table far larger
 * than one PHP invocation's memory or time budget is carried across ticks the same way
 * a large file is (ADR-0013). A slice is bounded by a row count AND a byte count and
 * ends at whichever is reached first, because rows are not what a host kills a request
 * over — a table of few very fat rows fits any row budget and still exceeds every byte
 * and time budget there is. While the row budget is what bounds a slice, cuts land on
 * extended-`INSERT` boundaries and the SQL is byte-identical however that budget is
 * tuned; a byte-bounded cut lands instead on the row that fills the budget, which is
 * the price of the slice being bounded in the unit the host actually enforces.
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
 * The column names the paging orders and seeks on are read from the engine's own
 * index catalog and never from the caller, so they are trusted on the same grounds.
 *
 * @since 0.1.0
 */
final class Table_Dumper {

	/**
	 * Rows emitted per extended `INSERT` statement.
	 *
	 * Batching keeps the dump close to `mysqldump --extended-insert` while bounding
	 * how long any single statement grows on a wide or busy table. It is also the
	 * granularity a slice is rounded to, which is what keeps the emitted SQL
	 * independent of how the slice size is tuned.
	 *
	 * @since 0.1.0
	 */
	private const int ROWS_PER_INSERT = 100;

	/**
	 * Returns only the structure block for one table — DDL, no rows.
	 *
	 * The same `--` header, `DROP TABLE IF EXISTS`, and `CREATE TABLE` a table's first
	 * slice carries, but without any data block: a structure-only table (issue #16)
	 * carries its schema into the artifact so a reload recreates it, yet ships none of its rows —
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
	 * Renders one bounded slice of a table, resuming after the previous slice's key.
	 *
	 * This is the seam the chunked build draws a table through (ADR-0013). The first
	 * slice — the one at row offset zero — carries the structure block and the data
	 * header ahead of its rows, every later slice carries `INSERT` statements alone, so
	 * the slices concatenate into exactly the block a `mysqldump` reload expects. The
	 * row budget is rounded up to a whole number of extended-`INSERT` batches, so while
	 * it is what bounds the slice a boundary is always a statement boundary and the
	 * concatenation is byte-identical however `$max_rows` is tuned.
	 *
	 * `$max_bytes` is the second bound, and the one a host actually enforces. A table of
	 * few very fat rows sits far under any row budget and still exceeds the request's
	 * memory and execution-time ceilings; production met exactly that — a 726-row table
	 * of ~23 KB rows never finished a single slice under a 1,000-row budget. So the page
	 * is fetched as the row budget allows but only as many of its rows are RENDERED as
	 * the byte budget has room for, with at least one row always rendered so a slice
	 * cannot come back empty and stall the build.
	 *
	 * Which is why completeness is decided on two facts rather than one. A short page
	 * alone no longer means the end of the table, because a page cut short by the byte
	 * budget is short for an entirely different reason; reading it as the end would
	 * publish a silently truncated table that imports without a single error — far worse
	 * than the loud stall it replaced. The table is finished only when every fetched row
	 * was rendered AND the page came back shorter than asked for, and the cursor handed
	 * back is the last row RENDERED, never the last one fetched.
	 *
	 * Rows are read by keyset pagination on the table's primary key
	 * ({@see ordering_key()}), never `LIMIT`/`OFFSET`, because an offset walk re-scans
	 * and discards every row it has already passed and so degrades quadratically over
	 * exactly the large tables this exists for. A table with no primary key at all has
	 * nothing to seek on and falls back to an offset walk — see {@see fetch_rows()} for
	 * why that is the sound choice there rather than a worse one.
	 *
	 * @since 0.4.0
	 *
	 * @param string                  $table     The table to dump a slice of; must be an existing table name.
	 * @param array<int, string>|null $cursor    Key tuple the previous slice ended on, or null for the first slice.
	 * @param int                     $rows_done Rows of this table already rendered by earlier slices.
	 * @param int                     $max_rows  Upper bound on rows in this slice, rounded up to whole `INSERT` batches.
	 * @param int                     $max_bytes Upper bound on the rendered rows' bytes in this slice; at least one row is always rendered.
	 * @return array{0: string, 1: array<int, string>|null, 2: int, 3: bool} The slice's SQL, the
	 *         cursor the next slice resumes after, the running row count, and whether the table
	 *         is now fully rendered.
	 *
	 * @throws RuntimeException When the table is not in the live catalog, its definition
	 *                          cannot be read, or its primary key changed mid-dump.
	 */
	public function dump_chunk( string $table, ?array $cursor, int $rows_done, int $max_rows, int $max_bytes ): array {

		// Refuse anything not in the live catalog before the name reaches a query.
		$this->require_known_table( $table );

		// Resolve the paging key and hold a resumed cursor to it. A cursor whose arity no
		// longer matches the key means the primary key changed between ticks, so resuming
		// would seek with one ordering into rows written under another and silently skip
		// or repeat rows; fail instead, exactly as the file path fails when a file changes
		// while it is being packaged.
		$key = $this->ordering_key( $table );
		if ( $cursor !== null && count( $cursor ) !== count( $key ) ) {
			throw new RuntimeException( 'A table primary key changed while the table was being dumped.' );
		}

		// Read the next page in the key's own order, render as much of it as the byte
		// budget holds, and prefix the structure block and data header on the first
		// slice only.
		$limit = max( 1, (int) ceil( $max_rows / self::ROWS_PER_INSERT ) ) * self::ROWS_PER_INSERT;
		$rows = $this->fetch_rows( $table, $key, $cursor, $rows_done, $limit );
		[ $data, $rendered ] = $this->insert_statements( $table, $rows, $max_bytes );
		$sql = ( $rows_done === 0 ? $this->structure_sql( $table ) . $this->data_header( $table ) : '' ) . $data;

		// The table is finished only when the page was rendered whole AND came back
		// short. A short page alone is ambiguous now that the byte budget can cut one
		// off early, and resolving that ambiguity the cheap way would end a table
		// mid-row-set and publish a truncated dump that reloads without complaint. The
		// cursor is the last row RENDERED, so the next slice resumes at the first row
		// this one had no room for rather than skipping it.
		$fetched = count( $rows );

		return [ $sql, $this->cursor_of( array_slice( $rows, 0, $rendered ), $key ), $rows_done + $rendered, $rendered === $fetched && $fetched < $limit ];

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
	 * Builds the `--` header mysqldump writes above a table's data block.
	 *
	 * Emitted on the first slice only, so a table split across many slices carries it
	 * exactly once however the slices fall.
	 *
	 * @since 0.4.0
	 *
	 * @param string $table The validated table name.
	 * @return string The data block's header.
	 */
	private function data_header( string $table ): string {

		return "\n--\n-- Dumping data for table `{$table}`\n--\n\n";

	}

	/**
	 * Resolves the columns a table is paged by, or an empty list when it has none.
	 *
	 * Keyset pagination needs a key that is unique, indexed, and totally ordered, and
	 * a primary key is exactly that wherever one exists. Composite primary keys are
	 * included rather than skipped — WordPress ships one on `wp_term_relationships`,
	 * and skipping them would push a core table onto the fallback — with the columns
	 * returned in the index's own `Seq_in_index` order, so the seek predicate walks
	 * rows in the order the index already stores them. Primary-key columns are NOT
	 * NULL by definition, which is what lets the predicate be a plain comparison with
	 * no null handling.
	 *
	 * An empty result means the table has no primary key at all — a plugin table may
	 * ship without one — and the caller falls back to an offset walk.
	 *
	 * @since 0.4.0
	 *
	 * @param string $table The validated table name.
	 * @return array<int, string> The primary key's columns in index order, or an empty list.
	 */
	private function ordering_key( string $table ): array {

		/**
		 * The WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// SHOW KEYS is the engine's own index catalog, one row per key column; pick out
		// the primary key's rows and restore their declared order.
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}`", ARRAY_A ) ?? []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- an index listing changes nothing; identifier validated against the catalog and cannot be bound.
		$columns = [];
		foreach ( $keys as $row ) {
			$column = $row['Column_name'] ?? null;
			if ( ( $row['Key_name'] ?? null ) !== 'PRIMARY' || ! is_string( $column ) ) {
				continue;
			}
			$columns[ is_numeric( $row['Seq_in_index'] ?? null ) ? (int) $row['Seq_in_index'] : count( $columns ) ] = $column;
		}
		ksort( $columns );

		return array_values( $columns );

	}

	/**
	 * Reads the next page of rows, seeking past the cursor or walking by offset.
	 *
	 * A keyed table is read by keyset pagination: the engine seeks straight to the
	 * cursor through the primary-key index and returns the next page, so the cost of a
	 * page is the same whether it is the first or the thousandth.
	 *
	 * A table with no primary key has nothing to seek on and is walked by offset
	 * instead, deliberately WITHOUT an `ORDER BY`. The only totally-ordering expression
	 * left on such a table is every column at once, and sorting an unindexed table on
	 * all of its columns would force a full filesort for every page — strictly worse
	 * than the offset scan it would replace, on exactly the large tables this exists
	 * for. The unordered walk assumes only that the engine returns an unmodified table
	 * in a stable order, which is precisely the assumption mysqldump's own single
	 * unordered scan makes, and no dump here is transactional anyway.
	 *
	 * @since 0.4.0
	 *
	 * @param string                  $table  The validated table name.
	 * @param array<int, string>      $key    The paging key's columns, empty for a keyless table.
	 * @param array<int, string>|null $cursor Key tuple the previous page ended on, or null.
	 * @param int                     $offset Rows already read, the keyless walk's position.
	 * @param int                     $limit  Rows to read at most.
	 * @return array<int, array<mixed>> The page's rows, column => value.
	 *
	 * @throws RuntimeException When the read fails, so a page that came back empty
	 *                          because of a failure is never read as the end of the table.
	 */
	private function fetch_rows( string $table, array $key, ?array $cursor, int $offset, int $limit ): array {

		/**
		 * The WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// Compose the page query rather than prepare it whole, because an identifier
		// cannot be a bound parameter. Only three kinds of thing reach the statement: the
		// table name and the key's column names, both read from the engine's own catalogs
		// and never from a caller; the window bounds, which are int-typed and so cannot
		// carry SQL at all; and the cursor values, which {@see keyset_predicate()} escapes
		// and quotes through `$wpdb->prepare()` before splicing them in.
		$where = $cursor === null ? '' : ' WHERE ' . $this->keyset_predicate( $key, $cursor );
		$window = $key === [] ? " LIMIT {$limit} OFFSET {$offset}" : ' ORDER BY ' . $this->order_by( $key ) . " LIMIT {$limit}";
		$query = "SELECT * FROM `{$table}`{$where}{$window}";

		// Read the page as an associative array so column order follows the table's own
		// definition, the order the column-less INSERT relies on.
		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dumping a table's own rows; see the composition above for why the statement is assembled, and nothing here is cacheable.

		// A read that failed and a page that is genuinely empty are indistinguishable in
		// the return value, so the error flag is what separates them. Reading a failed
		// read as the end of the table is what would publish a silently truncated dump
		// that imports without a single error — the one outcome this class exists to
		// prevent. wpdb clears the flag at the start of every query, so it describes
		// this read alone.
		if ( $wpdb->last_error !== '' ) {
			throw new RuntimeException( 'Unable to read a page of rows while dumping a table.' );
		}

		return is_array( $rows ) ? $rows : [];

	}

	/**
	 * Builds the predicate that resumes strictly after a given key tuple.
	 *
	 * The lexicographic expansion of `(k1, …, kn) > (v1, …, vn)`:
	 *
	 * ```
	 * ( k1 > v1 ) OR ( k1 = v1 AND k2 > v2 ) OR ( k1 = v1 AND k2 = v2 AND k3 > v3 ) …
	 * ```
	 *
	 * written out rather than expressed as the row-value comparison MySQL also
	 * understands, because the expansion is ordinary SQL that survives every engine and
	 * translation layer `$wpdb` may sit on, and it collapses to the single `k1 > v1`
	 * term for the one-column primary key that is the common case.
	 *
	 * Every cursor value is bound as a string. A numeric column compares numerically
	 * against a string literal, so the seek is correct either way, and re-typing the
	 * cursor here would be the same guess {@see literal()} deliberately refuses to make.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int, string> $key    The paging key's columns, in index order.
	 * @param array<int, string> $cursor The key tuple to resume after, same arity and order.
	 * @return string The predicate, with every cursor value already escaped and quoted.
	 */
	private function keyset_predicate( array $key, array $cursor ): string {

		/**
		 * The WordPress database access layer.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		// One OR term per key column: the columns before it pinned to equality, the
		// column itself strictly greater. Each value goes through prepare() against a
		// lone placeholder, which is how it is escaped and quoted despite the predicate
		// around it being assembled here.
		$terms = [];
		foreach ( $key as $depth => $column ) {
			$conditions = [];
			for ( $earlier = 0; $earlier < $depth; $earlier++ ) {
				$conditions[] = "`{$key[ $earlier ]}` = " . $wpdb->prepare( '%s', $cursor[ $earlier ] );
			}
			$conditions[] = "`{$column}` > " . $wpdb->prepare( '%s', $cursor[ $depth ] );
			$terms[] = '( ' . implode( ' AND ', $conditions ) . ' )';
		}

		return implode( ' OR ', $terms );

	}

	/**
	 * Reads the key tuple the next slice resumes after out of the page just rendered.
	 *
	 * Null when there is nothing to resume after: a keyless table, which the offset
	 * walk positions instead, or an empty page, which is the end of the table.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int, array<mixed>> $rows The page just rendered.
	 * @param array<int, string>       $key  The paging key's columns.
	 * @return array<int, string>|null The last row's key tuple, or null.
	 */
	private function cursor_of( array $rows, array $key ): ?array {

		if ( $key === [] || $rows === [] ) {
			return null;
		}
		$last = $rows[ array_key_last( $rows ) ];

		return array_map(
			static function ( string $column ) use ( $last ): string {
				$value = $last[ $column ] ?? null;
				return is_scalar( $value ) ? (string) $value : '';
			},
			$key,
		);

	}

	/**
	 * Renders as much of a page as the byte budget holds, as extended `INSERT`s.
	 *
	 * Rows are rendered one at a time and the budget is checked after each, so the
	 * cut lands on a row rather than on a whole statement batch: batch granularity
	 * would keep the emitted SQL independent of the budget but would let a batch of
	 * a hundred very fat rows overshoot it many times over, which is the failure this
	 * bound exists to prevent. The first row is always rendered even when it alone
	 * exceeds the budget — a slice that rendered nothing would advance no cursor and
	 * stall the build on a row it could never fit.
	 *
	 * The caller needs the count back, not just the SQL: it is what tells a partly
	 * rendered page apart from a page that simply ran out of table.
	 *
	 * @since 0.4.0
	 *
	 * @param string                   $table     The validated table name.
	 * @param array<int, array<mixed>> $rows      The rows available to render, in emission order.
	 * @param int                      $max_bytes Upper bound on the rendered rows' bytes.
	 * @return array{0: string, 1: int} The statements, each ending in a trailing newline
	 *         (empty for no rows), and how many rows they carry.
	 */
	private function insert_statements( string $table, array $rows, int $max_bytes ): array {

		// Render row by row, stopping at the first row that fills the budget, so the
		// slice's size is bounded in the unit the host kills a request over.
		$tuples = [];
		$bytes = 0;
		foreach ( $rows as $row ) {
			$tuple = $this->row_tuple( $row );
			$tuples[] = $tuple;
			$bytes += strlen( $tuple ) + 1;
			if ( $bytes >= $max_bytes ) {
				break;
			}
		}

		// Emit each batch of rendered rows as one extended INSERT, close to
		// mysqldump --extended-insert, so no single statement grows with the table.
		$sql = '';
		foreach ( array_chunk( $tuples, self::ROWS_PER_INSERT ) as $batch ) {
			$sql .= "INSERT INTO `{$table}` VALUES " . implode( ',', $batch ) . ";\n";
		}

		return [ $sql, count( $tuples ) ];

	}

	/**
	 * Quotes the paging key's columns into an `ORDER BY` list.
	 *
	 * The names come from the engine's own index catalog, never from a caller, so they
	 * are trusted on the same grounds as the validated table name.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int, string> $key The paging key's columns, in index order.
	 * @return string The quoted, comma-separated column list.
	 */
	private function order_by( array $key ): string {

		return implode( ', ', array_map( static fn( string $column ): string => "`{$column}`", $key ) );

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
