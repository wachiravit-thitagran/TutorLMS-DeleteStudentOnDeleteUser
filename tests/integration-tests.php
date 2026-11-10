<?php
/**
 * Integration test harness (no PHPUnit, no MySQL, no WordPress install).
 *
 * Usage:  php tests/integration-tests.php
 *
 * Unlike run-tests.php — which drives the cleanup service through a *fake*
 * repository — this harness exercises the REAL repository
 * (Tutor_User_Cleanup_Tutor_Data_Repository) end to end against a seeded,
 * in-memory database. It proves that the actual queries the plugin issues:
 *
 *   1. remove every category of learner data for the target user, and
 *   2. leave financial / instructor / other-user data completely untouched.
 *
 * The in-memory `MiniWpdb` executes the exact, closed set of query shapes the
 * repository produces (SELECT / DELETE / SHOW TABLES / SHOW COLUMNS). All
 * assertions read the stored rows *directly* (via MiniWpdb::rows()), so they do
 * not depend on the query parser — a parser bug shows up as a wrong deletion
 * result, which is exactly what we want to catch.
 *
 * QuizModel is deliberately undefined so quiz deletion uses the raw-SQL
 * fallback. A tutor_utils() stub intentionally returns only completed
 * enrollments, matching Tutor's helper, so the repository must derive course
 * IDs directly from every tutor_enrolled status before invoking add-on cleanup.
 *
 * @package TutorUserCleanup\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'TUTOR_VERSION' ) ) {
	define( 'TUTOR_VERSION', '4.0.1' ); // == tested version -> status 'ok'.
}

if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $key, $group = '' ) { return true; } }
if ( ! function_exists( 'clean_comment_cache' ) ) { function clean_comment_cache( $ids ) {} }

/**
 * Tiny in-memory database standing in for $wpdb.
 *
 * Tables are arrays of associative rows. A minimal SQL executor understands the
 * precise statements the plugin emits; anything else returns a safe default.
 */
class MiniWpdb {

	/** @var string */
	public $prefix = 'wp_';
	/** @var string */
	public $posts = 'wp_posts';
	/** @var string */
	public $comments = 'wp_comments';
	/** @var string */
	public $usermeta = 'wp_usermeta';
	/** @var string */
	public $postmeta = 'wp_postmeta';

	/** @var array<string,array<int,array<string,mixed>>> table => rows */
	private $tables = array();
	/** @var array<string,string[]> table => column names (for SHOW COLUMNS) */
	private $schema = array();

	/**
	 * Register a table with an explicit column list and optional seed rows.
	 *
	 * @param string                         $table   Prefixed table name.
	 * @param string[]                        $columns Column names.
	 * @param array<int,array<string,mixed>> $rows    Seed rows.
	 * @return void
	 */
	public function register( $table, array $columns, array $rows = array() ) {
		$this->schema[ $table ] = $columns;
		$this->tables[ $table ] = array_values( $rows );
	}

	/**
	 * Drop every table (so a scenario can start from a bare database and, in
	 * particular, test behaviour when optional tables do NOT exist).
	 *
	 * @return void
	 */
	public function reset() {
		$this->tables = array();
		$this->schema = array();
	}

	/**
	 * Direct row access for assertions (bypasses the SQL layer).
	 *
	 * @param string $table Prefixed table name.
	 * @return array<int,array<string,mixed>>
	 */
	public function rows( $table ) {
		return isset( $this->tables[ $table ] ) ? $this->tables[ $table ] : array();
	}

	/* --------------------------------------------------------------- */
	/* WordPress $wpdb surface used by the plugin                       */
	/* --------------------------------------------------------------- */

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_charset_collate() {
		return '';
	}

	/**
	 * Emulate $wpdb->prepare (%d, %s, %f). A single array argument is flattened,
	 * matching WordPress behaviour for IN () lists.
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[dsf]/',
			function ( $m ) use ( &$i, $args ) {
				$v = array_key_exists( $i, $args ) ? $args[ $i ] : '';
				++$i;
				if ( '%d' === $m[0] ) {
					return (string) (int) $v;
				}
				if ( '%f' === $m[0] ) {
					return (string) (float) $v;
				}
				return "'" . str_replace( "'", "''", (string) $v ) . "'";
			},
			$query
		);
	}

	public function get_var( $sql ) {
		$sql = $this->normalize( $sql );

		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/i", $sql, $m ) ) {
			return isset( $this->tables[ $m[1] ] ) ? $m[1] : null;
		}

		$res = $this->run_select( $sql );
		if ( is_int( $res ) ) {
			return $res; // COUNT(*)
		}
		return ! empty( $res ) ? $res[0] : null;
	}

	public function get_col( $sql ) {
		$res = $this->run_select( $this->normalize( $sql ) );
		if ( is_int( $res ) ) {
			return array( $res );
		}
		return is_array( $res ) ? $res : array();
	}

	public function get_results( $sql ) {
		$sql = $this->normalize( $sql );

		if ( preg_match( '/^SHOW COLUMNS FROM (\S+) LIKE \'(.+)\'$/i', $sql, $m ) ) {
			$table = $m[1];
			$col   = $m[2];
			$cols  = isset( $this->schema[ $table ] ) ? $this->schema[ $table ] : array();
			return in_array( $col, $cols, true ) ? array( (object) array( 'Field' => $col ) ) : array();
		}

		$res = $this->run_select( $sql );
		if ( ! is_array( $res ) ) {
			return array();
		}
		return array_map(
			function ( $v ) {
				return (object) array( 'value' => $v );
			},
			$res
		);
	}

	public function query( $sql ) {
		$sql = $this->normalize( $sql );

		if ( preg_match( '/^DELETE FROM (\S+)(?: WHERE (.+))?$/i', $sql, $m ) ) {
			$table = $m[1];
			$where = isset( $m[2] ) ? $m[2] : '';
			return $this->delete_where( $table, $where );
		}
		// CREATE / DROP / anything else: no-op success.
		return 0;
	}

	/**
	 * Structured delete: $wpdb->delete( $table, $where_equalities, $formats ).
	 */
	public function delete( $table, array $where, $formats = null ) {
		if ( ! isset( $this->tables[ $table ] ) ) {
			return 0;
		}
		$removed = 0;
		$kept    = array();
		foreach ( $this->tables[ $table ] as $row ) {
			$match = true;
			foreach ( $where as $col => $val ) {
				if ( ! array_key_exists( $col, $row ) || (string) $row[ $col ] !== (string) $val ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				++$removed;
			} else {
				$kept[] = $row;
			}
		}
		$this->tables[ $table ] = $kept;
		return $removed;
	}

	/**
	 * Structured insert (only needed if logging is ever enabled in a test).
	 */
	public function insert( $table, array $data, $formats = null ) {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = array();
			$this->schema[ $table ] = array_keys( $data );
		}
		$this->tables[ $table ][] = $data;
		return 1;
	}

	/* --------------------------------------------------------------- */
	/* Post helpers used by wp_delete_post()/get_post_type() stubs      */
	/* --------------------------------------------------------------- */

	/**
	 * Remove a post row and all of its postmeta. Returns true if a row went.
	 */
	public function remove_post( $id ) {
		$id      = (int) $id;
		$existed = false;

		if ( isset( $this->tables[ $this->posts ] ) ) {
			$kept = array();
			foreach ( $this->tables[ $this->posts ] as $row ) {
				if ( (int) $row['ID'] === $id ) {
					$existed = true;
					continue;
				}
				$kept[] = $row;
			}
			$this->tables[ $this->posts ] = $kept;
		}

		if ( isset( $this->tables[ $this->postmeta ] ) ) {
			$kept = array();
			foreach ( $this->tables[ $this->postmeta ] as $row ) {
				if ( (int) $row['post_id'] === $id ) {
					continue;
				}
				$kept[] = $row;
			}
			$this->tables[ $this->postmeta ] = $kept;
		}

		return $existed;
	}

	/**
	 * post_type for an ID, or '' when the post is gone.
	 */
	public function post_type_of( $id ) {
		$id = (int) $id;
		foreach ( $this->rows( $this->posts ) as $row ) {
			if ( (int) $row['ID'] === $id ) {
				return (string) $row['post_type'];
			}
		}
		return '';
	}

	/**
	 * Delete usermeta rows by user + exact key (for delete_user_meta()).
	 */
	public function remove_user_meta( $user_id, $key ) {
		if ( ! isset( $this->tables[ $this->usermeta ] ) ) {
			return 0;
		}
		$user_id = (int) $user_id;
		$removed = 0;
		$kept    = array();
		foreach ( $this->tables[ $this->usermeta ] as $row ) {
			if ( (int) $row['user_id'] === $user_id && (string) $row['meta_key'] === (string) $key ) {
				++$removed;
				continue;
			}
			$kept[] = $row;
		}
		$this->tables[ $this->usermeta ] = $kept;
		return $removed;
	}

	/* --------------------------------------------------------------- */
	/* Minimal SQL executor                                             */
	/* --------------------------------------------------------------- */

	private function normalize( $sql ) {
		return trim( preg_replace( '/\s+/', ' ', (string) $sql ) );
	}

	/**
	 * Execute a SELECT. Returns an int for COUNT(*), otherwise a list of the
	 * selected column's values.
	 *
	 * @return int|array<int,mixed>
	 */
	private function run_select( $sql ) {
		if ( ! preg_match( '/^SELECT\s+(.+?)\s+FROM\s+(\S+)(?:\s+WHERE\s+(.+))?$/i', $sql, $m ) ) {
			return array();
		}
		$cols  = trim( $m[1] );
		$table = $m[2];
		$where = isset( $m[3] ) ? $m[3] : '';

		$rows = $this->select_where( $table, $where );

		if ( preg_match( '/COUNT\(\*\)/i', $cols ) ) {
			return count( $rows );
		}

		$distinct = false;
		if ( preg_match( '/^DISTINCT\s+(.+)$/i', $cols, $dm ) ) {
			$distinct = true;
			$cols     = trim( $dm[1] );
		}
		$col    = $cols;
		$values = array();
		foreach ( $rows as $row ) {
			$values[] = array_key_exists( $col, $row ) ? $row[ $col ] : null;
		}
		if ( $distinct ) {
			$values = array_values( array_unique( $values ) );
		}
		return $values;
	}

	/**
	 * Rows in $table matching a parsed WHERE clause.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function select_where( $table, $where ) {
		if ( ! isset( $this->tables[ $table ] ) ) {
			return array();
		}
		$conds = $this->parse_conditions( $where );
		$out   = array();
		foreach ( $this->tables[ $table ] as $row ) {
			if ( $this->row_matches( $row, $conds ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Remove rows in $table matching a parsed WHERE clause. Returns count.
	 */
	private function delete_where( $table, $where ) {
		if ( ! isset( $this->tables[ $table ] ) ) {
			return 0;
		}
		$conds   = $this->parse_conditions( $where );
		$removed = 0;
		$kept    = array();
		foreach ( $this->tables[ $table ] as $row ) {
			if ( $this->row_matches( $row, $conds ) ) {
				++$removed;
			} else {
				$kept[] = $row;
			}
		}
		$this->tables[ $table ] = $kept;
		return $removed;
	}

	/**
	 * Parse an ` AND `-joined WHERE into a list of {col, op, val|list} conditions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_conditions( $where ) {
		$where = trim( $where );
		if ( '' === $where ) {
			return array();
		}
		$parts = explode( ' AND ', $where );
		$conds = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^(\w+)\s+IN\s*\((.*)\)$/i', $part, $m ) ) {
				$list = array_map(
					array( $this, 'unquote' ),
					array_map( 'trim', explode( ',', $m[2] ) )
				);
				$conds[] = array(
					'col' => $m[1],
					'op'  => 'in',
					'val' => $list,
				);
			} elseif ( preg_match( '/^(\w+)\s+LIKE\s+(.+)$/i', $part, $m ) ) {
				$conds[] = array(
					'col' => $m[1],
					'op'  => 'like',
					'val' => $this->unquote( trim( $m[2] ) ),
				);
			} elseif ( preg_match( '/^(\w+)\s*!=\s*(.+)$/', $part, $m ) ) {
				$conds[] = array(
					'col' => $m[1],
					'op'  => 'neq',
					'val' => $this->unquote( trim( $m[2] ) ),
				);
			} elseif ( preg_match( '/^(\w+)\s*=\s*(.+)$/', $part, $m ) ) {
				$conds[] = array(
					'col' => $m[1],
					'op'  => 'eq',
					'val' => $this->unquote( trim( $m[2] ) ),
				);
			}
		}
		return $conds;
	}

	/**
	 * @param array<string,mixed>            $row   Row.
	 * @param array<int,array<string,mixed>> $conds Parsed conditions.
	 * @return bool
	 */
	private function row_matches( array $row, array $conds ) {
		foreach ( $conds as $c ) {
			$col    = $c['col'];
			$actual = array_key_exists( $col, $row ) ? (string) $row[ $col ] : null;
			switch ( $c['op'] ) {
				case 'eq':
					if ( (string) $c['val'] !== $actual ) {
						return false;
					}
					break;
				case 'neq':
					if ( (string) $c['val'] === $actual ) {
						return false;
					}
					break;
				case 'in':
					$hay = array_map( 'strval', $c['val'] );
					if ( ! in_array( $actual, $hay, true ) ) {
						return false;
					}
					break;
				case 'like':
					if ( null === $actual || ! preg_match( $this->like_to_regex( $c['val'] ), $actual ) ) {
						return false;
					}
					break;
			}
		}
		return true;
	}

	private function unquote( $v ) {
		$v = trim( (string) $v );
		if ( strlen( $v ) >= 2 && "'" === $v[0] && "'" === substr( $v, -1 ) ) {
			$v = substr( $v, 1, -1 );
			$v = str_replace( "''", "'", $v );
		}
		return $v;
	}

	/**
	 * Convert a SQL LIKE pattern (honouring backslash escapes produced by
	 * esc_like) into an anchored PHP regex. `%` => .*, `_` => ., `\x` => literal x.
	 */
	private function like_to_regex( $pattern ) {
		$out = '';
		$len = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $pattern[ $i ];
			if ( '\\' === $ch && $i + 1 < $len ) {
				$out .= preg_quote( $pattern[ ++$i ], '/' );
			} elseif ( '%' === $ch ) {
				$out .= '.*';
			} elseif ( '_' === $ch ) {
				$out .= '.';
			} else {
				$out .= preg_quote( $ch, '/' );
			}
		}
		return '/^' . $out . '$/';
	}
}

/* ================================================================== */
/* WordPress function stubs the REAL repository calls.                  */
/* Defined BEFORE bootstrap-stubs.php so ours win its function_exists   */
/* guards (notably a real delete_user_meta and a tutor() marker).       */
/* ================================================================== */

$GLOBALS['wpdb'] = new MiniWpdb();

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $id, $force = false ) {
		return $GLOBALS['wpdb']->remove_post( $id ) ? (int) $id : false;
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id ) {
		$t = $GLOBALS['wpdb']->post_type_of( $id );
		return '' === $t ? false : $t;
	}
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key ) {
		$GLOBALS['wpdb']->remove_user_meta( $user_id, $key );
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		return true;
	}
}

class ITC_Tutor_Utils {
	public $reset_courses = array();

	public function get_enrolled_courses_ids_by_user( $user_id ) {
		unset( $user_id );
		return array( 500, 600 ); // Deliberately omits pending course 650.
	}

	public function delete_course_progress( $course_id, $user_id ) {
		unset( $user_id );
		$this->reset_courses[] = (int) $course_id;
	}
}

$GLOBALS['itc_tutor_utils'] = new ITC_Tutor_Utils();

if ( ! function_exists( 'tutor_utils' ) ) {
	function tutor_utils() {
		return $GLOBALS['itc_tutor_utils'];
	}
}

// Marker so Tutor_User_Cleanup_Tutor_Data_Repository::is_tutor_active() is true.
// tutor_version() reads TUTOR_VERSION first, so this object's shape is only used
// by the instructor post-type fallback.
if ( ! function_exists( 'tutor' ) ) {
	function tutor() {
		return (object) array( 'version' => TUTOR_VERSION );
	}
}

// The remaining generic stubs (options, WP_Error, current_user_can, get_userdata,
// absint, sanitize_text_field, etc.) come from the shared bootstrap.
require __DIR__ . '/bootstrap-stubs.php';

$base = dirname( __DIR__ );
require $base . '/includes/class-settings.php';
require $base . '/includes/class-logger.php';
require $base . '/includes/class-tutor-data-repository.php';
require $base . '/includes/class-cleanup-service.php';

/* ================================================================== */
/* Assertion helpers                                                   */
/* ================================================================== */

$GLOBALS['tuc_tests']  = 0;
$GLOBALS['tuc_failed'] = 0;

function itc_assert( $cond, $label ) {
	++$GLOBALS['tuc_tests'];
	echo ( $cond ? '  PASS: ' : '  FAIL: ' ) . $label . "\n";
	if ( ! $cond ) {
		++$GLOBALS['tuc_failed'];
	}
}

function itc_assert_eq( $expected, $actual, $label ) {
	$ok = ( $expected === $actual );
	itc_assert( $ok, $label );
	if ( ! $ok ) {
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	}
}

/**
 * Count rows in a table whose columns match all of $filter (loose string eq).
 */
function itc_count( $table, array $filter = array() ) {
	$rows = $GLOBALS['wpdb']->rows( $table );
	$n    = 0;
	foreach ( $rows as $row ) {
		$match = true;
		foreach ( $filter as $col => $val ) {
			if ( ! array_key_exists( $col, $row ) || (string) $row[ $col ] !== (string) $val ) {
				$match = false;
				break;
			}
		}
		if ( $match ) {
			++$n;
		}
	}
	return $n;
}

/**
 * Seed a complete, realistic dataset for two users:
 *   - user 42 (TARGET) — learner data of every type, PLUS financial/instructor data.
 *   - user 77 (BYSTANDER) — learner data that must survive untouched.
 *
 * @return void
 */
function itc_seed( array $skip = array() ) {
	/** @var MiniWpdb $wpdb */
	$wpdb = $GLOBALS['wpdb'];

	// Start from a bare database so a scenario can omit optional tables.
	$wpdb->reset();
	$GLOBALS['itc_tutor_utils']->reset_courses = array();

	$wpdb->register(
		'wp_posts',
		array( 'ID', 'post_type', 'post_author', 'post_parent', 'post_status' ),
		array(
			// user 42 enrollments (learner data -> removed).
			array( 'ID' => 1001, 'post_type' => 'tutor_enrolled', 'post_author' => 42, 'post_parent' => 500, 'post_status' => 'completed' ),
			array( 'ID' => 1002, 'post_type' => 'tutor_enrolled', 'post_author' => 42, 'post_parent' => 600, 'post_status' => 'completed' ),
			array( 'ID' => 1003, 'post_type' => 'tutor_enrolled', 'post_author' => 42, 'post_parent' => 650, 'post_status' => 'pending' ),
			// user 77 enrollment (must remain).
			array( 'ID' => 1101, 'post_type' => 'tutor_enrolled', 'post_author' => 77, 'post_parent' => 500, 'post_status' => 'completed' ),
			// user 42 authored a course as instructor (must remain).
			array( 'ID' => 700, 'post_type' => 'courses', 'post_author' => 42, 'post_parent' => 0, 'post_status' => 'publish' ),
			// user 42 WooCommerce order (financial -> must remain).
			array( 'ID' => 900, 'post_type' => 'shop_order', 'post_author' => 42, 'post_parent' => 0, 'post_status' => 'wc-completed' ),
		)
	);

	$wpdb->register(
		'wp_postmeta',
		array( 'meta_id', 'post_id', 'meta_key', 'meta_value' ),
		array(
			array( 'meta_id' => 1, 'post_id' => 1001, 'meta_key' => '_tutor_enrolled_by_order_id', 'meta_value' => 900 ),
			array( 'meta_id' => 2, 'post_id' => 1002, 'meta_key' => '_tutor_enrolled_by_product_id', 'meta_value' => 55 ),
			array( 'meta_id' => 4, 'post_id' => 1003, 'meta_key' => '_tutor_enrolled_by_product_id', 'meta_value' => 56 ),
			array( 'meta_id' => 3, 'post_id' => 700, 'meta_key' => '_tutor_course_price_type', 'meta_value' => 'paid' ), // instructor course meta, must remain.
		)
	);

	$wpdb->register(
		'wp_comments',
		array( 'comment_ID', 'user_id', 'comment_post_ID', 'comment_type', 'comment_agent' ),
		array(
			// user 42 course completions (removed).
			array( 'comment_ID' => 2001, 'user_id' => 42, 'comment_post_ID' => 500, 'comment_type' => 'course_completed', 'comment_agent' => 'TutorLMSPlugin' ),
			array( 'comment_ID' => 2002, 'user_id' => 42, 'comment_post_ID' => 600, 'comment_type' => 'course_completed', 'comment_agent' => 'TutorLMSPlugin' ),
			// user 42 review + Q&A (must remain).
			array( 'comment_ID' => 2003, 'user_id' => 42, 'comment_post_ID' => 500, 'comment_type' => 'tutor_course_rating', 'comment_agent' => 'TutorLMSPlugin' ),
			array( 'comment_ID' => 2004, 'user_id' => 42, 'comment_post_ID' => 600, 'comment_type' => 'tutor_q_and_a', 'comment_agent' => 'TutorLMSPlugin' ),
			// DECOY: same comment_type but written by a different agent — another
			// plugin's row. The repository filters on comment_agent, so this must
			// survive. Invisible to count_course_completions() by design.
			array( 'comment_ID' => 2005, 'user_id' => 42, 'comment_post_ID' => 500, 'comment_type' => 'course_completed', 'comment_agent' => 'SomeOtherPlugin' ),
			// user 77 completion (must remain).
			array( 'comment_ID' => 2101, 'user_id' => 77, 'comment_post_ID' => 500, 'comment_type' => 'course_completed', 'comment_agent' => 'TutorLMSPlugin' ),
		)
	);

	$wpdb->register(
		'wp_usermeta',
		array( 'umeta_id', 'user_id', 'meta_key', 'meta_value' ),
		array(
			// user 42 lesson completions (removed).
			array( 'umeta_id' => 1, 'user_id' => 42, 'meta_key' => '_tutor_completed_lesson_id_101', 'meta_value' => 1700000000 ),
			array( 'umeta_id' => 2, 'user_id' => 42, 'meta_key' => '_tutor_completed_lesson_id_102', 'meta_value' => 1700000001 ),
			// user 42 aggregate reading info (removed via delete_user_meta).
			array( 'umeta_id' => 3, 'user_id' => 42, 'meta_key' => '_lesson_reading_info', 'meta_value' => 'a:0:{}' ),
			// user 42 unrelated meta (must remain).
			array( 'umeta_id' => 4, 'user_id' => 42, 'meta_key' => 'nickname', 'meta_value' => 'Neo' ),
			// DECOYS for the LIKE pattern. In SQL, `_` is a single-char wildcard, so
			// the repository escapes it via esc_like(). If that escaping regressed,
			// '_tutor_completed_lesson_idX999' would match and be wrongly deleted.
			// The 'xx' prefix decoy proves the pattern stays anchored at the start.
			array( 'umeta_id' => 6, 'user_id' => 42, 'meta_key' => '_tutor_completed_lesson_idX999', 'meta_value' => 'decoy' ),
			array( 'umeta_id' => 7, 'user_id' => 42, 'meta_key' => 'xx_tutor_completed_lesson_id_1', 'meta_value' => 'decoy' ),
			// user 77 lesson completion (must remain).
			array( 'umeta_id' => 5, 'user_id' => 77, 'meta_key' => '_tutor_completed_lesson_id_201', 'meta_value' => 1700000002 ),
		)
	);

	if ( ! in_array( 'wp_tutor_quiz_attempts', $skip, true ) ) {
		$wpdb->register(
		'wp_tutor_quiz_attempts',
		array( 'attempt_id', 'user_id', 'quiz_id', 'course_id' ),
		array(
			array( 'attempt_id' => 10, 'user_id' => 42, 'quiz_id' => 301, 'course_id' => 500 ),
			array( 'attempt_id' => 11, 'user_id' => 42, 'quiz_id' => 302, 'course_id' => 500 ),
			array( 'attempt_id' => 12, 'user_id' => 42, 'quiz_id' => 303, 'course_id' => 600 ),
			array( 'attempt_id' => 20, 'user_id' => 77, 'quiz_id' => 301, 'course_id' => 500 ), // must remain.
		)
		);
	}

	if ( ! in_array( 'wp_tutor_quiz_attempt_answers', $skip, true ) ) {
		$wpdb->register(
			'wp_tutor_quiz_attempt_answers',
			array( 'answer_id', 'quiz_attempt_id', 'user_id' ),
			array(
				array( 'answer_id' => 5001, 'quiz_attempt_id' => 10, 'user_id' => 42 ),
				array( 'answer_id' => 5002, 'quiz_attempt_id' => 11, 'user_id' => 42 ),
				array( 'answer_id' => 5003, 'quiz_attempt_id' => 12, 'user_id' => 42 ),
				array( 'answer_id' => 5901, 'quiz_attempt_id' => 20, 'user_id' => 77 ), // must remain.
			)
		);
	}

	if ( ! in_array( 'wp_tutor_gradebooks_results', $skip, true ) ) {
		$wpdb->register(
			'wp_tutor_gradebooks_results',
			array( 'gradebook_result_id', 'user_id', 'course_id' ),
			array(
				array( 'gradebook_result_id' => 8001, 'user_id' => 42, 'course_id' => 500 ),
				array( 'gradebook_result_id' => 8002, 'user_id' => 42, 'course_id' => 600 ),
				array( 'gradebook_result_id' => 8901, 'user_id' => 77, 'course_id' => 500 ), // must remain.
			)
		);
	}

	// ---- Preserved data stores (must be untouched) ----
	$wpdb->register(
		'wp_tutor_earnings',
		array( 'earning_id', 'user_id', 'amount' ),
		array( array( 'earning_id' => 1, 'user_id' => 42, 'amount' => 250 ) )
	);
	$wpdb->register(
		'wp_tutor_withdraws',
		array( 'withdraw_id', 'user_id', 'amount' ),
		array( array( 'withdraw_id' => 1, 'user_id' => 42, 'amount' => 100 ) )
	);
	$wpdb->register(
		'wp_tutor_orders',
		array( 'id', 'user_id', 'total' ),
		array( array( 'id' => 1, 'user_id' => 42, 'total' => 500 ) )
	);
	$wpdb->register(
		'wp_tutor_quiz_questions',
		array( 'question_id', 'quiz_id' ),
		array( array( 'question_id' => 1, 'quiz_id' => 301 ) )
	);

	// The log table is intentionally NOT registered; logging is disabled in the
	// test settings, so Logger::log() short-circuits before touching $wpdb.
}

/**
 * Install deterministic settings (all types on, logging off, live mode).
 */
function itc_install_settings( array $overrides = array() ) {
	$settings = array_merge(
		array(
			'wipe_enrollments'          => true,
			'wipe_course_progress'      => true,
			'wipe_quiz_attempts'        => true,
			'wipe_gradebook'            => true,
			'wipe_cache'                => true,
				'enable_logging'            => false,
			'log_retention_days'        => 180,
			'preserve_financial'        => true,
			'allow_unsupported_version' => false,
		),
		$overrides
	);
	update_option( Tutor_User_Cleanup_Settings::OPTION_KEY, $settings );
}

/* ================================================================== */
/* Scenario A — LIVE reset wipes every learner type, preserves the rest */
/* ================================================================== */
echo "\n[A] live wipe of user 42 (real repository, seeded DB)\n";

tuc_reset_stub_state();
itc_install_settings();
$GLOBALS['_stub']['can']        = true;
$GLOBALS['_stub']['valid_uids'] = array( 42, 77 );
itc_seed();

// Sanity: the seed is what we think it is (independent of the cleanup logic).
itc_assert_eq( 3, itc_count( 'wp_posts', array( 'post_type' => 'tutor_enrolled', 'post_author' => 42 ) ), 'seed: user 42 has 3 enrollments across statuses' );
itc_assert_eq( 3, itc_count( 'wp_tutor_quiz_attempts', array( 'user_id' => 42 ) ), 'seed: user 42 has 3 quiz attempts' );

$repo = new Tutor_User_Cleanup_Tutor_Data_Repository();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

$summary = $svc->reset_learner( 42, false );

itc_assert( is_array( $summary ), 'reset_learner returned a summary array' );
itc_assert_eq( 'success', $summary['result'], 'live wipe result = success' );

// Reported counts reflect what was actually seeded.
itc_assert_eq( 3, $summary['counts']['enrollments'], 'counts.enrollments = 3' );
itc_assert_eq( 3, $summary['counts']['courses_reset'], 'counts.courses_reset includes pending enrollment course' );
itc_assert_eq( array(), $GLOBALS['itc_tutor_utils']->reset_courses, 'progress reset never invokes broad quiz and add-on helper' );
itc_assert_eq( 2, $summary['counts']['course_completions'], 'counts.course_completions = 2' );
itc_assert_eq( 2, $summary['counts']['lesson_completions'], 'counts.lesson_completions = 2' );
itc_assert_eq( 3, $summary['counts']['quiz_attempts'], 'counts.quiz_attempts = 3' );
itc_assert_eq( 2, $summary['counts']['gradebook_results'], 'counts.gradebook_results = 2' );

// --- Learner data for user 42 is GONE ---
itc_assert_eq( 0, itc_count( 'wp_posts', array( 'post_type' => 'tutor_enrolled', 'post_author' => 42 ) ), 'user 42 enrollments deleted' );
itc_assert_eq( 0, itc_count( 'wp_postmeta', array( 'post_id' => 1001 ) ), 'enrollment 1001 postmeta deleted with the post' );
itc_assert_eq( 0, itc_count( 'wp_postmeta', array( 'post_id' => 1002 ) ), 'enrollment 1002 postmeta deleted with the post' );
itc_assert_eq( 0, itc_count( 'wp_postmeta', array( 'post_id' => 1003 ) ), 'pending enrollment postmeta deleted with the post' );
itc_assert_eq( 0, itc_count( 'wp_comments', array( 'user_id' => 42, 'comment_type' => 'course_completed', 'comment_agent' => 'TutorLMSPlugin' ) ), 'user 42 course completions deleted' );
itc_assert_eq( 0, itc_count( 'wp_usermeta', array( 'user_id' => 42, 'meta_key' => '_tutor_completed_lesson_id_101' ) ), 'lesson completion 101 meta deleted' );
itc_assert_eq( 0, itc_count( 'wp_usermeta', array( 'user_id' => 42, 'meta_key' => '_tutor_completed_lesson_id_102' ) ), 'lesson completion 102 meta deleted' );
itc_assert_eq( 0, itc_count( 'wp_usermeta', array( 'user_id' => 42, 'meta_key' => '_lesson_reading_info' ) ), 'reading-info meta deleted' );
itc_assert_eq( 0, itc_count( 'wp_tutor_quiz_attempts', array( 'user_id' => 42 ) ), 'user 42 quiz attempts deleted' );
itc_assert_eq( 0, itc_count( 'wp_tutor_quiz_attempt_answers', array( 'user_id' => 42 ) ), 'user 42 quiz answers deleted' );
itc_assert_eq( 0, itc_count( 'wp_tutor_gradebooks_results', array( 'user_id' => 42 ) ), 'user 42 gradebook results deleted' );

// --- Preserved data for user 42 is INTACT ---
itc_assert_eq( 1, itc_count( 'wp_posts', array( 'ID' => 700 ) ), 'instructor-authored course preserved' );
itc_assert_eq( 1, itc_count( 'wp_postmeta', array( 'post_id' => 700 ) ), 'instructor course meta preserved' );
itc_assert_eq( 1, itc_count( 'wp_posts', array( 'ID' => 900 ) ), 'WooCommerce order post preserved' );
itc_assert_eq( 1, itc_count( 'wp_comments', array( 'comment_ID' => 2003 ) ), 'course review preserved' );
itc_assert_eq( 1, itc_count( 'wp_comments', array( 'comment_ID' => 2004 ) ), 'Q&A comment preserved' );
itc_assert_eq( 1, itc_count( 'wp_usermeta', array( 'user_id' => 42, 'meta_key' => 'nickname' ) ), 'unrelated usermeta preserved' );
itc_assert_eq( 1, itc_count( 'wp_tutor_earnings', array( 'user_id' => 42 ) ), 'instructor earnings preserved' );
itc_assert_eq( 1, itc_count( 'wp_tutor_withdraws', array( 'user_id' => 42 ) ), 'withdrawals preserved' );
itc_assert_eq( 1, itc_count( 'wp_tutor_orders', array( 'user_id' => 42 ) ), 'Tutor native order preserved' );
itc_assert_eq( 1, itc_count( 'wp_tutor_quiz_questions', array( 'quiz_id' => 301 ) ), 'quiz definition preserved' );

// --- Bystander user 77 is COMPLETELY untouched ---
itc_assert_eq( 1, itc_count( 'wp_posts', array( 'post_type' => 'tutor_enrolled', 'post_author' => 77 ) ), 'user 77 enrollment untouched' );
itc_assert_eq( 1, itc_count( 'wp_comments', array( 'user_id' => 77, 'comment_type' => 'course_completed' ) ), 'user 77 completion untouched' );
itc_assert_eq( 1, itc_count( 'wp_usermeta', array( 'user_id' => 77, 'meta_key' => '_tutor_completed_lesson_id_201' ) ), 'user 77 lesson meta untouched' );
itc_assert_eq( 1, itc_count( 'wp_tutor_quiz_attempts', array( 'user_id' => 77 ) ), 'user 77 quiz attempt untouched' );
itc_assert_eq( 1, itc_count( 'wp_tutor_quiz_attempt_answers', array( 'user_id' => 77 ) ), 'user 77 quiz answer untouched' );
itc_assert_eq( 1, itc_count( 'wp_tutor_gradebooks_results', array( 'user_id' => 77 ) ), 'user 77 gradebook untouched' );

/* ================================================================== */
/* Scenario B — DRY-RUN reports counts but deletes nothing             */
/* ================================================================== */
echo "\n[B] dry-run preview of user 42 deletes nothing\n";

tuc_reset_stub_state();
itc_install_settings();
$GLOBALS['_stub']['can']        = true;
$GLOBALS['_stub']['valid_uids'] = array( 42, 77 );
itc_seed(); // fresh dataset.

$repo = new Tutor_User_Cleanup_Tutor_Data_Repository();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

$before_total =
	itc_count( 'wp_posts' ) + itc_count( 'wp_postmeta' ) + itc_count( 'wp_comments' ) +
	itc_count( 'wp_usermeta' ) + itc_count( 'wp_tutor_quiz_attempts' ) +
	itc_count( 'wp_tutor_quiz_attempt_answers' ) + itc_count( 'wp_tutor_gradebooks_results' );

$summary = $svc->reset_learner( 42, true ); // dry-run.

itc_assert_eq( 'success', $summary['result'], 'dry-run result = success' );
itc_assert_eq( 3, $summary['counts']['quiz_attempts'], 'dry-run still reports quiz_attempts = 3' );
itc_assert_eq( 3, $summary['counts']['enrollments'], 'dry-run still reports enrollments = 3' );

$after_total =
	itc_count( 'wp_posts' ) + itc_count( 'wp_postmeta' ) + itc_count( 'wp_comments' ) +
	itc_count( 'wp_usermeta' ) + itc_count( 'wp_tutor_quiz_attempts' ) +
	itc_count( 'wp_tutor_quiz_attempt_answers' ) + itc_count( 'wp_tutor_gradebooks_results' );

itc_assert_eq( $before_total, $after_total, 'dry-run left every table row-for-row unchanged' );
itc_assert_eq( 3, itc_count( 'wp_posts', array( 'post_type' => 'tutor_enrolled', 'post_author' => 42 ) ), 'dry-run kept user 42 enrollments' );

/* ================================================================== */
/* Scenario C — version block: live wipe on a too-new Tutor is a no-op  */
/* ================================================================== */
echo "\n[C] version guard blocks live deletion, keeps data\n";

tuc_reset_stub_state();
itc_install_settings(); // override off.
$GLOBALS['_stub']['can']        = true;
$GLOBALS['_stub']['valid_uids'] = array( 42, 77 );
itc_seed();

// A repository subclass that reports a newer-than-tested version while keeping
// all the real query logic.
$repo_toonew = new class() extends Tutor_User_Cleanup_Tutor_Data_Repository {
	public function tutor_version() {
		return '99.0.0';
	}
};
$svc = new Tutor_User_Cleanup_Cleanup_Service( $repo_toonew );

$summary = $svc->reset_learner( 42, false ); // LIVE, but should be blocked.

itc_assert_eq( 'blocked', $summary['result'], 'live wipe blocked on too-new version' );
itc_assert_eq( 3, itc_count( 'wp_posts', array( 'post_type' => 'tutor_enrolled', 'post_author' => 42 ) ), 'blocked wipe left enrollments in place' );
itc_assert_eq( 3, itc_count( 'wp_tutor_quiz_attempts', array( 'user_id' => 42 ) ), 'blocked wipe left quiz attempts in place' );

/* ================================================================== */
/* Scenario D — query precision: the deletes must not over-match       */
/* ================================================================== */
echo "\n[D] query precision (LIKE escaping, agent filter, post-type guard)\n";

tuc_reset_stub_state();
itc_install_settings();
$GLOBALS['_stub']['can']        = true;
$GLOBALS['_stub']['valid_uids'] = array( 42, 77 );
itc_seed();

$repo = new Tutor_User_Cleanup_Tutor_Data_Repository();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$svc->reset_learner( 42, false );

// esc_like() must escape the `_` wildcard, or this key would be swept up.
itc_assert_eq( 1, itc_count( 'wp_usermeta', array( 'meta_key' => '_tutor_completed_lesson_idX999' ) ), 'LIKE underscore is escaped (decoy meta survives)' );
// The pattern is a prefix match anchored at the start of the key.
itc_assert_eq( 1, itc_count( 'wp_usermeta', array( 'meta_key' => 'xx_tutor_completed_lesson_id_1' ) ), 'LIKE pattern is prefix-anchored (decoy meta survives)' );
// Another plugin's course_completed row (different comment_agent) is not ours.
itc_assert_eq( 1, itc_count( 'wp_comments', array( 'comment_ID' => 2005 ) ), 'foreign-agent course_completed row survives' );

// delete_enrollment() refuses anything that is not a tutor_enrolled post, so a
// bad/stale ID can never destroy an order or a course.
itc_seed(); // restore.
$repo2 = new Tutor_User_Cleanup_Tutor_Data_Repository();
itc_assert_eq( false, $repo2->delete_enrollment( 900 ), 'delete_enrollment refuses a shop_order ID' );
itc_assert_eq( 1, itc_count( 'wp_posts', array( 'ID' => 900 ) ), 'the order post is still present' );
itc_assert_eq( false, $repo2->delete_enrollment( 700 ), 'delete_enrollment refuses a course ID' );
itc_assert_eq( 1, itc_count( 'wp_posts', array( 'ID' => 700 ) ), 'the course post is still present' );
itc_assert_eq( false, $repo2->delete_enrollment( 0 ), 'delete_enrollment refuses ID 0' );
itc_assert_eq( false, $repo2->delete_enrollment( 999999 ), 'delete_enrollment refuses a nonexistent ID' );

/* ================================================================== */
/* Scenario E — a learner with no Tutor data at all                    */
/* ================================================================== */
echo "\n[E] user with zero learner data\n";

tuc_reset_stub_state();
itc_install_settings();
$GLOBALS['_stub']['can']        = true;
$GLOBALS['_stub']['valid_uids'] = array( 42, 77, 88 );
itc_seed();

$repo = new Tutor_User_Cleanup_Tutor_Data_Repository();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

$rows_before = itc_count( 'wp_posts' ) + itc_count( 'wp_comments' ) + itc_count( 'wp_usermeta' ) + itc_count( 'wp_tutor_quiz_attempts' );
$summary     = $svc->reset_learner( 88, false ); // user 88 has nothing seeded.
$rows_after  = itc_count( 'wp_posts' ) + itc_count( 'wp_comments' ) + itc_count( 'wp_usermeta' ) + itc_count( 'wp_tutor_quiz_attempts' );

itc_assert_eq( 'success', $summary['result'], 'empty user wipes cleanly' );
itc_assert_eq( 0, array_sum( $summary['counts'] ), 'empty user reports all-zero counts' );
itc_assert_eq( $rows_before, $rows_after, 'wiping an empty user changes no rows at all' );

/* ================================================================== */
/* Scenario F — optional Pro tables absent (no fatal, no false counts)  */
/* ================================================================== */
echo "\n[F] optional tables missing (gradebook + quiz)\n";

tuc_reset_stub_state();
itc_install_settings();
$GLOBALS['_stub']['can']        = true;
$GLOBALS['_stub']['valid_uids'] = array( 42, 77 );
itc_seed( array( 'wp_tutor_gradebooks_results', 'wp_tutor_quiz_attempts', 'wp_tutor_quiz_attempt_answers' ) );

$repo = new Tutor_User_Cleanup_Tutor_Data_Repository();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$summary = $svc->reset_learner( 42, false );

itc_assert_eq( 'success', $summary['result'], 'missing optional tables do not break the run' );
itc_assert_eq( 0, $summary['counts']['gradebook_results'], 'absent gradebook table reports 0' );
itc_assert_eq( 0, $summary['counts']['quiz_attempts'], 'absent quiz table reports 0' );
// The data that DOES exist is still cleaned.
itc_assert_eq( 0, itc_count( 'wp_posts', array( 'post_type' => 'tutor_enrolled', 'post_author' => 42 ) ), 'enrollments still wiped without the Pro tables' );
itc_assert_eq( 0, itc_count( 'wp_comments', array( 'user_id' => 42, 'comment_type' => 'course_completed', 'comment_agent' => 'TutorLMSPlugin' ) ), 'completions still wiped without the Pro tables' );

/* ================================================================== */
/* Scenario G — bulk: two users in one request                         */
/* ================================================================== */
echo "\n[G] bulk wipe of two users in one request\n";

tuc_reset_stub_state();
itc_install_settings();
$GLOBALS['_stub']['can']        = true;
$GLOBALS['_stub']['valid_uids'] = array( 42, 77 );
itc_seed();

$repo = new Tutor_User_Cleanup_Tutor_Data_Repository();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

$s42 = $svc->reset_learner( 42, false );
$s77 = $svc->reset_learner( 77, false );

itc_assert_eq( 'success', $s42['result'], 'bulk: user 42 wiped' );
itc_assert_eq( 'success', $s77['result'], 'bulk: user 77 wiped' );
itc_assert_eq( 0, itc_count( 'wp_posts', array( 'post_type' => 'tutor_enrolled' ) ), 'bulk: no enrollments remain for either user' );
itc_assert_eq( 0, itc_count( 'wp_tutor_quiz_attempts' ), 'bulk: no quiz attempts remain' );
itc_assert_eq( 0, itc_count( 'wp_tutor_quiz_attempt_answers' ), 'bulk: no quiz answers remain' );

// Financial + instructor data still survives a full two-user sweep.
itc_assert_eq( 1, itc_count( 'wp_tutor_earnings' ), 'bulk: earnings preserved' );
itc_assert_eq( 1, itc_count( 'wp_tutor_withdraws' ), 'bulk: withdrawals preserved' );
itc_assert_eq( 1, itc_count( 'wp_tutor_orders' ), 'bulk: orders preserved' );
itc_assert_eq( 1, itc_count( 'wp_posts', array( 'ID' => 700 ) ), 'bulk: instructor course preserved' );
itc_assert_eq( 1, itc_count( 'wp_posts', array( 'ID' => 900 ) ), 'bulk: WooCommerce order preserved' );

// Re-wiping an already-processed user in the same request is a no-op.
$again = $svc->reset_learner( 42, false );
itc_assert_eq( 'skipped', $again['result'], 'bulk: repeat wipe of the same user is skipped' );

/* ================================================================== */
/* Summary                                                             */
/* ================================================================== */
echo "\n--------------------------------------------------\n";
printf( "Integration: ran %d assertions, %d failed.\n", $GLOBALS['tuc_tests'], $GLOBALS['tuc_failed'] );
echo "--------------------------------------------------\n";

exit( $GLOBALS['tuc_failed'] > 0 ? 1 : 0 );
