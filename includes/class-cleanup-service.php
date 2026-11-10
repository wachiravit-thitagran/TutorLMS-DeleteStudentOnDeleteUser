<?php
/**
 * Orchestrates the learner-data cleanup pipeline.
 *
 * Responsibilities:
 *  - Validate the request (capability, nonce, checkbox, Tutor active, user exists).
 *  - Run the ordered wipe pipeline for a single user, honouring the settings
 *    toggles and dry-run mode.
 *  - Isolate errors per user so one failure never aborts a bulk operation.
 *  - Log every run.
 *
 * @package TutorUserCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Tutor_User_Cleanup_Cleanup_Service
 */
class Tutor_User_Cleanup_Cleanup_Service {

	/**
	 * Data repository.
	 *
	 * @var Tutor_User_Cleanup_Tutor_Data_Repository
	 */
	private $repo;

	/**
	 * User IDs already processed in this PHP request (dedupe guard).
	 *
	 * @var array<int,bool>
	 */
	private $processed = array();

	/**
	 * Constructor.
	 *
	 * @param Tutor_User_Cleanup_Tutor_Data_Repository|null $repo Optional injected repo (tests).
	 */
	public function __construct( $repo = null ) {
		$this->repo = $repo instanceof Tutor_User_Cleanup_Tutor_Data_Repository
			? $repo
			: new Tutor_User_Cleanup_Tutor_Data_Repository();
	}

	/** Explain the fail-closed multisite boundary consistently in every UI. */
	public static function multisite_block_message() {
		return __( 'Site-local Tutor cleanup is unavailable on multisite because Tutor progress uses network-shared user metadata. No cleanup is planned or performed. Whole-account cleanup is available only through explicit network user deletion.', 'tutor-user-cleanup' );
	}

	/*
	|----------------------------------------------------------------------
	| Request validation
	|----------------------------------------------------------------------
	*/

	/**
	 * Whether the current request opted in to cleanup (checkbox ticked).
	 *
	 * @return bool
	 */
	public function is_cleanup_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked separately in can_process().
		return ! empty( $_REQUEST['tutor_user_cleanup'] );
	}

	/**
	 * Whether the current request opted in to dry-run.
	 *
	 * @return bool
	 */
	public function is_dry_run_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked separately in can_process().
		return ! empty( $_REQUEST['tutor_user_cleanup_dry_run'] );
	}

	/**
	 * Validate that we may run cleanup for this request/user.
	 *
	 * @param int    $user_id     Target user ID.
	 * @param string $nonce_action Nonce action to verify ('delete-users' or 'ms-users-delete').
	 * @return true|WP_Error True if allowed, WP_Error describing the failure otherwise.
	 */
	public function can_process( $user_id, $nonce_action = 'delete-users' ) {
		$user_id = absint( $user_id );

		// 1. Capability.
		$is_network_request = in_array( $nonce_action, array( 'ms-users-delete', 'deleteuser' ), true );
		$cap                = $is_network_request ? 'manage_network_users' : 'delete_users';
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'tuc_no_cap', __( 'Insufficient permissions to delete users.', 'tutor-user-cleanup' ) );
		}

		// 2. Nonce. WordPress core has already verified this before delete_user fires,
		//    but we re-check defensively so cleanup can never run on a forged request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			return new WP_Error( 'tuc_bad_nonce', __( 'Security check failed.', 'tutor-user-cleanup' ) );
		}

		// 3. Checkbox opt-in.
		if ( ! $this->is_cleanup_requested() ) {
			return new WP_Error( 'tuc_not_requested', __( 'Cleanup was not requested.', 'tutor-user-cleanup' ) );
		}

		// 4. Tutor active.
		if ( ! $this->repo->is_tutor_active() ) {
			return new WP_Error( 'tuc_no_tutor', __( 'Tutor LMS is not active.', 'tutor-user-cleanup' ) );
		}

		// 5. User exists.
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'tuc_no_user', __( 'Target user does not exist.', 'tutor-user-cleanup' ) );
		}

		return true;
	}

	/*
	|----------------------------------------------------------------------
	| Entry points
	|----------------------------------------------------------------------
	*/

	/**
	 * Handle a single user being deleted from the WP delete-user flow.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $nonce_action Nonce action for this context.
	 * @return array|WP_Error Result summary or error.
	 */
	public function handle_user_deletion( $user_id, $nonce_action = 'delete-users' ) {
		$can = $this->can_process( $user_id, $nonce_action );
		if ( is_wp_error( $can ) ) {
			// Only log genuine failures, not the common "not requested" no-op.
			if ( 'tuc_not_requested' !== $can->get_error_code() ) {
				Tutor_User_Cleanup_Logger::log(
					array(
						'target_user_id' => $user_id,
						'context'        => 'delete_user',
						'result'         => 'skipped',
						'message'        => $can->get_error_message(),
					)
				);
			}
			return $can;
		}

		// The surrounding WordPress account deletion is always live and cannot be
		// stopped from the delete_user hook. Running only a preview here would delete
		// the account while leaving Tutor custom-table rows orphaned.
		$context = in_array( $nonce_action, array( 'ms-users-delete', 'deleteuser' ), true )
			? 'network_delete_user' : 'delete_user';
		return $this->wipe_user( $user_id, $context, false );
	}

	/**
	 * Reset a learner without deleting the WP account (explicit admin action).
	 *
	 * @param int  $user_id User ID.
	 * @param bool $dry_run Preview only.
	 * @return array|WP_Error
	 */
	public function reset_learner( $user_id, $dry_run = false ) {
		$user_id = absint( $user_id );

		if ( ! current_user_can( 'delete_users' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'tuc_no_cap', __( 'Insufficient permissions.', 'tutor-user-cleanup' ) );
		}
		if ( ! $this->repo->is_tutor_active() ) {
			return new WP_Error( 'tuc_no_tutor', __( 'Tutor LMS is not active.', 'tutor-user-cleanup' ) );
		}
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'tuc_no_user', __( 'Target user does not exist.', 'tutor-user-cleanup' ) );
		}

		return $this->wipe_user( $user_id, 'reset_learner', (bool) $dry_run );
	}

	/*
	|----------------------------------------------------------------------
	| Core pipeline
	|----------------------------------------------------------------------
	*/

	/**
	 * Run (or preview) the wipe pipeline for one user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $context 'delete_user' | 'network_delete_user' | 'reset_learner'.
	 * @param bool   $dry_run When true, only count — do not delete.
	 * @return array{result:string,dry_run:bool,counts:array<string,int>,operations:array<string,array<string,mixed>>,user_id:int,message:string}
	 */
	public function wipe_user( $user_id, $context, $dry_run = false ) {
		$user_id = absint( $user_id );

		// Tutor stores lesson IDs and aggregate progress in network-shared usermeta
		// without a blog namespace. Even a course-scoped helper cannot distinguish
		// equal IDs on two sites. Refuse site-local operations before hooks or reads;
		// only the explicit, capability/nonce-checked network account deletion may
		// perform the existing whole-account cleanup. Preview must report this same
		// block rather than advertise deletions that cannot safely be executed.
		if ( is_multisite() && ( 'network_delete_user' !== $context || ! current_user_can( 'manage_network_users' ) ) ) {
			$blocked = array(
				'result'     => 'blocked',
				'dry_run'    => (bool) $dry_run,
				'counts'     => $this->empty_counts(),
				'operations' => array(),
				'user_id'    => $user_id,
				'message'    => self::multisite_block_message(),
			);
			Tutor_User_Cleanup_Logger::log( array_merge( $blocked, array( 'target_user_id' => $user_id, 'context' => $context ) ) );
			return $blocked;
		}

		// Dedupe within this request.
		$processed_key = get_current_blog_id() . ':' . $user_id;
		if ( isset( $this->processed[ $processed_key ] ) ) {
			return array(
				'result'     => 'skipped',
				'dry_run'    => $dry_run,
				'counts'     => array(),
				'operations' => array(),
				'user_id'    => $user_id,
				'message'    => 'already_processed',
			);
		}
		$this->processed[ $processed_key ] = true;

		$counts     = $this->empty_counts();
		$operations = array();
		$messages   = array();

		// Version gate: refuse LIVE deletion on a Tutor version outside the tested
		// range (most importantly, newer than tested) unless an admin has explicitly
		// overridden — a changed schema could delete the wrong rows. Dry-run/preview
		// is always allowed because it performs no writes.
		$version_status = $this->repo->get_version_support_status();
		if ( 'ok' !== $version_status ) {
			$version_message = $this->version_status_message( $version_status );
			$messages[]      = $version_message;

			if ( ! $dry_run && ! $this->is_version_override_enabled( $version_status ) ) {
				$blocked = array(
					'result'     => 'blocked',
					'dry_run'    => false,
					'counts'     => $counts, // all zero — nothing was touched.
					'operations' => array(),
					'user_id'    => $user_id,
					'message'    => $version_message,
				);

				Tutor_User_Cleanup_Logger::log(
					array(
						'target_user_id' => $user_id,
						'context'        => $context,
						'dry_run'        => false,
						'result'         => 'blocked',
						'counts'         => $counts,
						'operations'     => array(),
						'message'        => $version_message,
					)
				);

				return $blocked;
			}
		}

		/**
		 * Fires before a user's Tutor data is wiped.
		 *
		 * @param int    $user_id User ID.
		 * @param bool   $dry_run Dry-run flag.
		 * @param string $context Context string.
		 */
		try {
			do_action( 'tutor_user_cleanup_before_user', $user_id, $dry_run, $context );
		} catch ( \Throwable $e ) {
			$operations['before_hook'] = $this->error_operation( 'before_hook', $e );
			$messages[]                = 'before_hook: ' . $e->getMessage();
		}

		// 1. Quiz attempts + answers (whole-user sweep; covers orphans too).
		if ( Tutor_User_Cleanup_Settings::is_type_enabled( 'quiz_attempts' ) ) {
			try {
				$attempt_ids = $this->repo->get_quiz_attempt_ids( $user_id );
				$counts['quiz_attempts'] = count( $attempt_ids );
				$operations['quiz_attempts'] = $this->execute_bulk_operation(
					'quiz_attempts',
					$counts['quiz_attempts'],
					$dry_run,
					function () use ( $attempt_ids ) {
						return $this->repo->delete_quiz_attempts( $attempt_ids );
					}
				);
			} catch ( \Throwable $e ) {
				$operations['quiz_attempts'] = $this->error_operation( 'quiz_attempts', $e );
				$messages[]                  = 'quiz_attempts: ' . $e->getMessage();
			}
		}

		// 2. Per-course progress and orphan completion sweeps.
		if ( Tutor_User_Cleanup_Settings::is_type_enabled( 'course_progress' ) ) {
			try {
				$counts['course_completions'] = $this->repo->count_course_completions( $user_id );
				$operations['course_completions'] = $this->execute_bulk_operation(
					'course_completions',
					$counts['course_completions'],
					$dry_run,
					function () use ( $user_id ) {
						return $this->repo->delete_course_completions( $user_id );
					}
				);
			} catch ( \Throwable $e ) {
				$operations['course_completions'] = $this->error_operation( 'course_completions', $e );
				$messages[]                       = 'course_completions: ' . $e->getMessage();
			}

			try {
				$counts['lesson_completions'] = $this->repo->count_lesson_completions( $user_id );
				$operations['lesson_completions'] = $this->execute_bulk_operation(
					'lesson_completions',
					$counts['lesson_completions'],
					$dry_run,
					function () use ( $user_id ) {
						return $this->repo->delete_lesson_completions( $user_id );
					}
				);
			} catch ( \Throwable $e ) {
				$operations['lesson_completions'] = $this->error_operation( 'lesson_completions', $e );
				$messages[]                       = 'lesson_completions: ' . $e->getMessage();
			}

			// Run only the narrow course-completion operation. Broad Tutor
			// progress helpers also delete disabled quiz/add-on categories.
			try {
				$course_ids = $this->repo->get_enrolled_course_ids( $user_id );
				$counts['courses_reset'] = count( $course_ids );
				$operations['courses_reset'] = $this->execute_item_operation(
					'courses_reset',
					$course_ids,
					$dry_run,
					function ( $course_id ) use ( $user_id ) {
						return $this->repo->reset_course_progress( $course_id, $user_id );
					}
				);
			} catch ( \Throwable $e ) {
				$operations['courses_reset'] = $this->error_operation( 'courses_reset', $e );
				$messages[]                  = 'courses_reset: ' . $e->getMessage();
			}
		}

		// 3. Enrollment posts.
		if ( Tutor_User_Cleanup_Settings::is_type_enabled( 'enrollments' ) ) {
			try {
				$enrollment_ids = $this->repo->get_enrollment_ids( $user_id );
				$counts['enrollments'] = count( $enrollment_ids );
				$operations['enrollments'] = $this->execute_item_operation(
					'enrollments',
					$enrollment_ids,
					$dry_run,
					function ( $enrollment_id ) {
						return $this->repo->delete_enrollment( $enrollment_id );
					}
				);
			} catch ( \Throwable $e ) {
				$operations['enrollments'] = $this->error_operation( 'enrollments', $e );
				$messages[]                = 'enrollments: ' . $e->getMessage();
			}
		}

		// 4. Gradebook results (Pro; only if table exists).
		if ( Tutor_User_Cleanup_Settings::is_type_enabled( 'gradebook' ) ) {
			try {
				$counts['gradebook_results'] = $this->repo->count_gradebook_results( $user_id );
				$operations['gradebook_results'] = $this->execute_bulk_operation(
					'gradebook_results',
					$counts['gradebook_results'],
					$dry_run,
					function () use ( $user_id ) {
						return $this->repo->delete_gradebook_results( $user_id );
					}
				);
			} catch ( \Throwable $e ) {
				$operations['gradebook_results'] = $this->error_operation( 'gradebook_results', $e );
				$messages[]                      = 'gradebook_results: ' . $e->getMessage();
			}
		}

		// 5. Cache.
		if ( Tutor_User_Cleanup_Settings::is_type_enabled( 'cache' ) ) {
			$operations['cache'] = $this->execute_bulk_operation(
				'cache',
				1,
				$dry_run,
				function () use ( $user_id ) {
					return $this->repo->clear_learner_cache( $user_id ) ? 1 : false;
				}
			);
		}

		try {
			do_action( 'tutor_user_cleanup_after_user', $user_id, $counts, $dry_run, $context );
		} catch ( \Throwable $e ) {
			$operations['after_hook'] = $this->error_operation( 'after_hook', $e );
			$messages[]               = 'after_hook: ' . $e->getMessage();
		}

		foreach ( $operations as $operation ) {
			if ( ! empty( $operation['message'] ) ) {
				$messages[] = $operation['key'] . ': ' . $operation['message'];
			}
		}

		$result  = $this->result_from_operations( $operations, $dry_run );
		$message = implode( '; ', array_values( array_unique( array_filter( $messages ) ) ) );

		$summary = array(
			'result'     => $result,
			'dry_run'    => (bool) $dry_run,
			'counts'     => $counts,
			'operations' => $operations,
			'user_id'    => $user_id,
			'message'    => $message,
		);

		Tutor_User_Cleanup_Logger::log(
			array(
				'target_user_id' => $user_id,
				'context'        => $context,
				'dry_run'        => (bool) $dry_run,
				'result'         => $result,
				'counts'         => $counts,
				'operations'     => $operations,
				'message'        => $message,
			)
		);

		return $summary;
	}

	/**
	 * Process a network user on every site they belong to.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array|WP_Error> Results keyed by site ID.
	 */
	public function handle_network_user_deletion( $user_id ) {
		$blogs = get_blogs_of_user( absint( $user_id ) );
		$site_ids = array();
		foreach ( (array) $blogs as $blog ) {
			if ( is_object( $blog ) && isset( $blog->userblog_id ) ) {
				$site_ids[] = absint( $blog->userblog_id );
			}
		}
		$site_ids = array_values( array_unique( array_filter( $site_ids ) ) );

		if ( empty( $site_ids ) ) {
			$site_ids[] = get_current_blog_id();
		}

		$results = array();
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				$results[ $site_id ] = $this->handle_user_deletion( $user_id, 'ms-users-delete' );
			} finally {
				restore_current_blog();
			}
		}

		return $results;
	}

	/**
	 * Execute one bulk operation and report requested versus actual rows.
	 *
	 * @param string   $key       Operation key.
	 * @param int      $requested Requested rows/items.
	 * @param bool     $dry_run   Preview flag.
	 * @param callable $callback  Live deletion callback.
	 * @return array<string,mixed>
	 */
	private function execute_bulk_operation( $key, $requested, $dry_run, $callback ) {
		$requested = max( 0, (int) $requested );
		if ( $dry_run ) {
			return $this->operation_result( $key, $requested, 0, 0, 'dry_run' );
		}
		if ( 0 === $requested ) {
			return $this->operation_result( $key, 0, 0, 0, 'success' );
		}

		try {
			$deleted = call_user_func( $callback );
			if ( false === $deleted ) {
				return $this->operation_result( $key, $requested, 0, $requested, 'error', 'deletion_failed' );
			}
			$deleted = max( 0, min( $requested, (int) $deleted ) );
			$failed  = $requested - $deleted;
			return $this->operation_result( $key, $requested, $deleted, $failed, $failed ? 'partial' : 'success' );
		} catch ( \Throwable $e ) {
			return $this->operation_result( $key, $requested, 0, $requested, 'error', $e->getMessage() );
		}
	}

	/**
	 * Execute an operation one item at a time so one failure does not stop peers.
	 *
	 * @param string   $key      Operation key.
	 * @param array    $items    Items to process.
	 * @param bool     $dry_run  Preview flag.
	 * @param callable $callback Per-item callback.
	 * @return array<string,mixed>
	 */
	private function execute_item_operation( $key, array $items, $dry_run, $callback ) {
		$requested = count( $items );
		if ( $dry_run ) {
			return $this->operation_result( $key, $requested, 0, 0, 'dry_run' );
		}

		$deleted = 0;
		$errors  = array();
		foreach ( $items as $item ) {
			try {
				if ( call_user_func( $callback, $item ) ) {
					++$deleted;
				} else {
					$errors[] = 'item_' . absint( $item ) . '_failed';
				}
			} catch ( \Throwable $e ) {
				$errors[] = 'item_' . absint( $item ) . ': ' . $e->getMessage();
			}
		}

		$failed = $requested - $deleted;
		return $this->operation_result(
			$key,
			$requested,
			$deleted,
			$failed,
			$failed ? ( $deleted ? 'partial' : 'error' ) : 'success',
			implode( ', ', $errors )
		);
	}

	/**
	 * Normalized operation result.
	 *
	 * @return array<string,mixed>
	 */
	private function operation_result( $key, $requested, $deleted, $failed, $status, $message = '' ) {
		return array(
			'key'       => (string) $key,
			'requested' => (int) $requested,
			'deleted'   => (int) $deleted,
			'failed'    => (int) $failed,
			'status'    => (string) $status,
			'message'   => (string) $message,
		);
	}

	/**
	 * Build an operation error when even discovery/counting failed.
	 *
	 * @param string     $key Operation key.
	 * @param \Throwable $e   Failure.
	 * @return array<string,mixed>
	 */
	private function error_operation( $key, \Throwable $e ) {
		return $this->operation_result( $key, 0, 0, 1, 'error', $e->getMessage() );
	}

	/**
	 * Derive the overall result without hiding partial deletion.
	 *
	 * @param array<string,array<string,mixed>> $operations Operations.
	 * @param bool                              $dry_run    Preview flag.
	 * @return string
	 */
	private function result_from_operations( array $operations, $dry_run ) {
		if ( $dry_run ) {
			return 'success';
		}

		$failed  = 0;
		$deleted = 0;
		foreach ( $operations as $operation ) {
			$failed  += isset( $operation['failed'] ) ? (int) $operation['failed'] : 0;
			$deleted += isset( $operation['deleted'] ) ? (int) $operation['deleted'] : 0;
		}
		if ( $failed > 0 ) {
			return $deleted > 0 ? 'partial' : 'error';
		}
		return 'success';
	}

	/**
	 * Whether the admin has authorised cleanup on an out-of-range Tutor version.
	 *
	 * Two ways to override: the "allow_unsupported_version" setting, or the
	 * `tutor_user_cleanup_allow_unsupported_version` filter (for developers).
	 *
	 * @param string $status Version status.
	 * @return bool
	 */
	private function is_version_override_enabled( $status ) {
		$allow = (bool) Tutor_User_Cleanup_Settings::get( 'allow_unsupported_version', false );

		/**
		 * Filter the version-block override.
		 *
		 * @param bool   $allow   Whether to allow cleanup on an untested version.
		 * @param string $version Detected Tutor LMS version.
		 * @param string $status  'too_old' | 'too_new' | 'unknown'.
		 */
		return (bool) apply_filters(
			'tutor_user_cleanup_allow_unsupported_version',
			$allow,
			$this->repo->tutor_version(),
			$status
		);
	}

	/**
	 * Human-readable message for a version status.
	 *
	 * @param string $status Version status.
	 * @return string
	 */
	private function version_status_message( $status ) {
		$detected = $this->repo->tutor_version();
		$detected = '' === $detected ? 'unknown' : $detected;

		switch ( $status ) {
			case 'too_new':
				return sprintf(
					/* translators: 1: detected version, 2: tested-up-to version. */
					__( 'Detected Tutor LMS %1$s is newer than the version this plugin was tested against (%2$s). The data structure may have changed, so live cleanup was skipped to avoid deleting the wrong records. Enable the version override only after verifying compatibility.', 'tutor-user-cleanup' ),
					$detected,
					TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION
				);
			case 'too_old':
				return sprintf(
					/* translators: 1: detected version, 2: minimum supported version. */
					__( 'Detected Tutor LMS %1$s is older than the minimum supported version (%2$s). Live cleanup was skipped. Enable the version override only after verifying compatibility.', 'tutor-user-cleanup' ),
					$detected,
					TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION
				);
				case 'unknown':
					return __( 'Could not detect the Tutor LMS version, so compatibility could not be verified. Live cleanup requires an explicit version override.', 'tutor-user-cleanup' );
			default:
				return '';
		}
	}

	/**
	 * Zeroed counts scaffold.
	 *
	 * @return array<string,int>
	 */
	private function empty_counts() {
		return array(
			'enrollments'        => 0,
			'courses_reset'      => 0,
			'course_completions' => 0,
			'lesson_completions' => 0,
			'quiz_attempts'      => 0,
			'gradebook_results'  => 0,
		);
	}
}
