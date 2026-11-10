<?php
/**
 * Data access layer for Tutor LMS learner data.
 *
 * Every method that touches Tutor storage lives here so version differences,
 * table-existence checks and column checks are contained in one place.
 *
 * Verified against the current Tutor LMS source:
 *  - Enrollments:        CPT `tutor_enrolled` (post_author = user, post_parent = course).
 *  - Course completion:  wp_comments (comment_type = 'course_completed', comment_agent = 'TutorLMSPlugin').
 *  - Lesson completion:  usermeta `_tutor_completed_lesson_id_{lesson_id}` and `_lesson_reading_info`.
 *  - Quiz attempts:      table `{prefix}tutor_quiz_attempts` (attempt_id, user_id, ...).
 *  - Quiz answers:       table `{prefix}tutor_quiz_attempt_answers` (FK quiz_attempt_id -> attempt_id).
 *  - Gradebook (Pro):    table `{prefix}tutor_gradebooks_results` (user_id).
 *
 * PRESERVED (never touched here): tutor_earnings, tutor_withdraws, WC/Tutor orders,
 * quiz *definition* tables, reviews (tutor_course_rating), Q&A (tutor_q_and_a),
 * instructor-authored courses.
 *
 * @package TutorUserCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Tutor_User_Cleanup_Tutor_Data_Repository
 */
class Tutor_User_Cleanup_Tutor_Data_Repository {

	/**
	 * Cache of table-existence checks for this request.
	 *
	 * @var array<string,bool>
	 */
	private $table_cache = array();

	/**
	 * Is Tutor LMS active/loaded?
	 *
	 * @return bool
	 */
	public function is_tutor_active() {
		return function_exists( 'tutor_utils' ) || function_exists( 'tutor' ) || class_exists( '\TUTOR\Tutor' );
	}

	/**
	 * Detected Tutor LMS version, or empty string.
	 *
	 * @return string
	 */
	public function tutor_version() {
		if ( defined( 'TUTOR_VERSION' ) ) {
			return (string) TUTOR_VERSION;
		}
		if ( function_exists( 'tutor' ) ) {
			$tutor = tutor();
			if ( is_object( $tutor ) && isset( $tutor->version ) ) {
				return (string) $tutor->version;
			}
		}
		return '';
	}

	/**
	 * Classify the installed Tutor LMS version against the supported range.
	 *
	 * @return string One of 'ok', 'too_old', 'too_new', 'unknown'.
	 */
	public function get_version_support_status() {
		$version = $this->tutor_version();
		if ( '' === $version ) {
			return 'unknown';
		}
		if ( version_compare( $version, TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION, '<' ) ) {
			return 'too_old';
		}
		if ( defined( 'TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION' )
			&& version_compare( $version, TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION, '>' ) ) {
			return 'too_new';
		}
		return 'ok';
	}

	/**
	 * Whether the detected version is inside the validated range (or undetectable).
	 * A hard block on live deletion is applied elsewhere for 'too_old'/'too_new'.
	 *
	 * @return bool
	 */
	public function is_supported_version() {
		return 'ok' === $this->get_version_support_status();
	}

	/*
	|----------------------------------------------------------------------
	| Schema helpers
	|----------------------------------------------------------------------
	*/

	/**
	 * Prefixed table name.
	 *
	 * @param string $unprefixed E.g. 'tutor_quiz_attempts'.
	 * @return string
	 */
	public function table( $unprefixed ) {
		global $wpdb;
		return $wpdb->prefix . $unprefixed;
	}

	/**
	 * True if a (prefixed) table exists. Cached per request.
	 *
	 * @param string $unprefixed Unprefixed table name.
	 * @return bool
	 */
	public function table_exists( $unprefixed ) {
		global $wpdb;
		$table = $this->table( $unprefixed );
		if ( isset( $this->table_cache[ $table ] ) ) {
			return $this->table_cache[ $table ];
		}
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->table_cache[ $table ] = $exists;
		return $exists;
	}

	/**
	 * True if a column exists on a (prefixed) table.
	 *
	 * @param string $unprefixed Unprefixed table name.
	 * @param string $column     Column name.
	 * @return bool
	 */
	public function column_exists( $unprefixed, $column ) {
		if ( ! $this->table_exists( $unprefixed ) ) {
			return false;
		}
		global $wpdb;
		$table = $this->table( $unprefixed );
		$found = $wpdb->get_results(
			$wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return ! empty( $found );
	}

	/*
	|----------------------------------------------------------------------
	| Enrollments (CPT tutor_enrolled)
	|----------------------------------------------------------------------
	*/

	/**
	 * All enrollment post IDs for a user, regardless of status.
	 *
	 * @param int $user_id User ID.
	 * @return int[] Post IDs.
	 */
	public function get_enrollment_ids( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d",
				'tutor_enrolled',
				$user_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Distinct enrolled course IDs for a user, regardless of enrollment status.
	 *
	 * @param int $user_id User ID.
	 * @return int[] Course IDs.
	 */
	public function get_enrolled_course_ids( $user_id ) {
		$user_id = absint( $user_id );

		// Tutor's get_enrolled_courses_ids_by_user() helper intentionally filters
		// to completed enrollment posts. Cleanup must include pending/cancelled
		// records too so add-ons receive delete_course_progress for every course.
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d",
				'tutor_enrolled',
				$user_id
			)
		);
		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Delete a single enrollment post (and its postmeta). Does NOT touch orders.
	 *
	 * @param int $enrollment_id Enrollment post ID.
	 * @return bool
	 */
	public function delete_enrollment( $enrollment_id ) {
		$enrollment_id = absint( $enrollment_id );
		if ( ! $enrollment_id ) {
			return false;
		}
		// Only delete if it really is a tutor_enrolled post — guards against mistakes.
		if ( 'tutor_enrolled' !== get_post_type( $enrollment_id ) ) {
			return false;
		}
		return (bool) wp_delete_post( $enrollment_id, true );
	}

	/*
	|----------------------------------------------------------------------
	| Course / lesson completion + per-course progress
	|----------------------------------------------------------------------
	*/

	/**
	 * Reset only this course completion. Lesson metadata is handled by the
	 * explicit lesson sweep. Never delegate to Tutor's broad reset: it also
	 * deletes quiz attempts and invokes unrelated assignment/add-on hooks.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id User ID.
	 * @return bool Whether the narrowly scoped database operation succeeded.
	 */
	public function reset_course_progress( $course_id, $user_id ) {
		global $wpdb;
		return false !== $wpdb->delete(
			$wpdb->comments,
			array(
				'user_id' => absint( $user_id ),
				'comment_post_ID' => absint( $course_id ),
				'comment_type' => 'course_completed',
				'comment_agent' => 'TutorLMSPlugin',
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Count course_completed comment rows for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function count_course_completions( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d AND comment_type = %s AND comment_agent = %s",
				absint( $user_id ),
				'course_completed',
				'TutorLMSPlugin'
			)
		);
	}

	/**
	 * Delete all course_completed comment rows for a user (orphan sweep).
	 *
	 * @param int $user_id User ID.
	 * @return int|false Rows removed, or false on database failure.
	 */
	public function delete_course_completions( $user_id ) {
		global $wpdb;
		$comment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d AND comment_type = %s AND comment_agent = %s",
				absint( $user_id ),
				'course_completed',
				'TutorLMSPlugin'
			)
		);
		$removed = $wpdb->delete(
			$wpdb->comments,
			array(
				'user_id'       => absint( $user_id ),
				'comment_type'  => 'course_completed',
				'comment_agent' => 'TutorLMSPlugin',
			),
			array( '%d', '%s', '%s' )
		);
		if ( false !== $removed && ! empty( $comment_ids ) ) {
			clean_comment_cache( array_map( 'intval', $comment_ids ) );
		}
		return $removed;
	}

	/**
	 * Count lesson-completion usermeta keys for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function count_lesson_completions( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				absint( $user_id ),
				$wpdb->esc_like( '_tutor_completed_lesson_id_' ) . '%'
			)
		);
	}

	/**
	 * Delete all lesson-completion usermeta for a user (orphan sweep).
	 *
	 * @param int $user_id User ID.
	 * @return int|false Rows removed, or false on database failure.
	 */
	public function delete_lesson_completions( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );

		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( '_tutor_completed_lesson_id_' ) . '%'
			)
		);

		// Raw SQL bypasses WordPress metadata invalidation, including when the
		// aggregate key is absent and delete_user_meta() would be a no-op.
		wp_cache_delete( $user_id, 'user_meta' );

		// Also drop the aggregate reading-info meta.
		delete_user_meta( $user_id, '_lesson_reading_info' );

		return false === $removed ? false : (int) $removed;
	}

	/*
	|----------------------------------------------------------------------
	| Quiz attempts + answers
	|----------------------------------------------------------------------
	*/

	/**
	 * All quiz attempt IDs for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int[] Attempt IDs.
	 */
	public function get_quiz_attempt_ids( $user_id ) {
		if ( ! $this->table_exists( 'tutor_quiz_attempts' ) ) {
			return array();
		}
		global $wpdb;
		$table = $this->table( 'tutor_quiz_attempts' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attempt_id FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Delete quiz attempts (and their answers) by attempt IDs.
	 *
	 * Prefers Tutor's QuizModel::delete_quiz_attempt() which also removes answer
	 * rows, any stored answer files, and fires `tutor_quiz/attempt_deleted`.
	 * Falls back to direct table deletes if the model is unavailable.
	 *
	 * @param int[] $attempt_ids Attempt IDs.
	 * @return int|false Number of attempts processed, or false when deletion fails.
	 */
	public function delete_quiz_attempts( array $attempt_ids ) {
		$attempt_ids = array_values( array_unique( array_filter( array_map( 'absint', $attempt_ids ) ) ) );
		if ( empty( $attempt_ids ) ) {
			return 0;
		}

		if ( class_exists( '\Tutor\Models\QuizModel' ) && method_exists( '\Tutor\Models\QuizModel', 'delete_quiz_attempt' ) ) {
			\Tutor\Models\QuizModel::delete_quiz_attempt( $attempt_ids );
			return count( $attempt_ids ) - $this->count_existing_quiz_attempts( $attempt_ids );
		}

		// Fallback: raw deletes (answers first, then attempts).
		global $wpdb;
		$attempts_table = $this->table( 'tutor_quiz_attempts' );
		$answers_table  = $this->table( 'tutor_quiz_attempt_answers' );

		$placeholders = implode( ',', array_fill( 0, count( $attempt_ids ), '%d' ) );

		if ( $this->table_exists( 'tutor_quiz_attempt_answers' ) ) {
			$answers_deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$answers_table} WHERE quiz_attempt_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$attempt_ids
				)
			);
			if ( false === $answers_deleted ) {
				return false;
			}
		}
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$attempts_table} WHERE attempt_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$attempt_ids
			)
		);

		if ( false === $deleted ) {
			return false;
		}

		return count( $attempt_ids ) - $this->count_existing_quiz_attempts( $attempt_ids );
	}

	/**
	 * Count a known set of attempt IDs that still exist after deletion.
	 *
	 * @param int[] $attempt_ids Attempt IDs.
	 * @return int
	 */
	private function count_existing_quiz_attempts( array $attempt_ids ) {
		if ( empty( $attempt_ids ) || ! $this->table_exists( 'tutor_quiz_attempts' ) ) {
			return 0;
		}

		global $wpdb;
		$table        = $this->table( 'tutor_quiz_attempts' );
		$placeholders = implode( ',', array_fill( 0, count( $attempt_ids ), '%d' ) );

		$remaining = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attempt_id FROM {$table} WHERE attempt_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$attempt_ids
			)
		);

		return count( (array) $remaining );
	}

	/*
	|----------------------------------------------------------------------
	| Gradebook (Tutor Pro) — learner results only
	|----------------------------------------------------------------------
	*/

	/**
	 * Count gradebook result rows for a user (Pro; table may not exist).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function count_gradebook_results( $user_id ) {
		if ( ! $this->table_exists( 'tutor_gradebooks_results' ) || ! $this->column_exists( 'tutor_gradebooks_results', 'user_id' ) ) {
			return 0;
		}
		global $wpdb;
		$table = $this->table( 'tutor_gradebooks_results' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			)
		);
	}

	/**
	 * Delete gradebook result rows for a user (Pro).
	 *
	 * @param int $user_id User ID.
	 * @return int|false Rows removed, or false on database failure.
	 */
	public function delete_gradebook_results( $user_id ) {
		if ( ! $this->table_exists( 'tutor_gradebooks_results' ) || ! $this->column_exists( 'tutor_gradebooks_results', 'user_id' ) ) {
			return 0;
		}
		global $wpdb;
		$table = $this->table( 'tutor_gradebooks_results' );
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $user_id )
			)
		);
		return false === $result ? false : (int) $result;
	}

	/*
	|----------------------------------------------------------------------
	| Cache
	|----------------------------------------------------------------------
	*/

	/**
	 * Clear known Tutor caches for a learner. Intentionally conservative — we do
	 * not flush the whole object cache.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function clear_learner_cache( $user_id ) {
		$user_id = absint( $user_id );

		// Tutor's own request-scoped cache helper, if present.
		if ( class_exists( '\TUTOR\Cache\CacheHandler' ) && method_exists( '\TUTOR\Cache\CacheHandler', 'get_cache_group_name' ) ) {
			// Not all versions expose per-user invalidation; the delete_* calls above
			// already clear the source rows, so we just bust common transients below.
			$this->maybe_delete_dashboard_transient( $user_id );
			return true;
		}

		$this->maybe_delete_dashboard_transient( $user_id );
		return true;
	}

	/**
	 * Delete common per-user dashboard transients if they exist.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function maybe_delete_dashboard_transient( $user_id ) {
		delete_transient( 'tutor_dashboard_' . $user_id );
		delete_transient( 'tutor_course_completed_percent_' . $user_id );
	}

	/*
	|----------------------------------------------------------------------
	| Instructor detection (used to warn, never to delete)
	|----------------------------------------------------------------------
	*/

	/**
	 * True if the user authored any Tutor courses (i.e. is an instructor).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function is_instructor( $user_id ) {
		global $wpdb;
		$course_pt = 'courses';
		if ( function_exists( 'tutor' ) ) {
			$tutor = tutor();
			if ( is_object( $tutor ) && ! empty( $tutor->course_post_type ) ) {
				$course_pt = $tutor->course_post_type;
			}
		}
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND post_status != 'trash'",
				$course_pt,
				absint( $user_id )
			)
		);
		return $count > 0;
	}
}
