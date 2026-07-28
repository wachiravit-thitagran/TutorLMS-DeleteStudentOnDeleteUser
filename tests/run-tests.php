<?php
/**
 * Standalone test runner (no PHPUnit / no WordPress required).
 *
 * Usage:  php tests/run-tests.php
 *
 * Verifies the plugin's pure logic: settings sanitization, the cleanup guard,
 * dry-run vs. live behaviour, dedupe, type toggles, and the financial-data
 * safety guarantee.
 *
 * @package TutorUserCleanup\Tests
 */

require __DIR__ . '/bootstrap-stubs.php';

$base = dirname( __DIR__ );
require $base . '/includes/class-settings.php';
require $base . '/includes/class-logger.php';
require $base . '/includes/class-tutor-data-repository.php';
require $base . '/includes/class-cleanup-service.php';

/* ------------------------------------------------------------------ */
/* Tiny assertion framework                                            */
/* ------------------------------------------------------------------ */

$GLOBALS['tuc_tests']  = 0;
$GLOBALS['tuc_failed'] = 0;

/**
 * Assert truthiness.
 *
 * @param bool   $cond  Condition.
 * @param string $label Test label.
 * @return void
 */
function tuc_assert( $cond, $label ) {
	++$GLOBALS['tuc_tests'];
	if ( $cond ) {
		echo "  PASS: {$label}\n";
	} else {
		++$GLOBALS['tuc_failed'];
		echo "  FAIL: {$label}\n";
	}
}

/**
 * Assert equality (with a readable diff).
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $label    Label.
 * @return void
 */
function tuc_assert_eq( $expected, $actual, $label ) {
	$ok = ( $expected === $actual );
	tuc_assert( $ok, $label );
	if ( ! $ok ) {
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	}
}

/**
 * Fake repository with call counters and canned data.
 */
class TUC_Fake_Repo extends Tutor_User_Cleanup_Tutor_Data_Repository {

	public $calls = array(
		'delete_quiz'     => 0,
		'reset_course'    => 0,
		'del_course_comp' => 0,
		'del_lesson'      => 0,
		'del_enroll'      => 0,
		'del_grade'       => 0,
		'cache'           => 0,
	);

	public $tutor_active = true;

	/** @var string One of ok|too_old|too_new|unknown. */
	public $version_status = 'ok';

	public function is_tutor_active() {
		return $this->tutor_active;
	}
	public function get_version_support_status() {
		return $this->version_status;
	}
	public function is_supported_version() {
		return 'ok' === $this->version_status;
	}
	public function tutor_version() {
		return '2.7.0';
	}

	public function get_quiz_attempt_ids( $user_id ) {
		return array( 10, 11, 12 );
	}
	public function delete_quiz_attempts( array $attempt_ids ) {
		++$this->calls['delete_quiz'];
		return count( $attempt_ids );
	}

	public function get_enrolled_course_ids( $user_id ) {
		return array( 100, 200 );
	}
	public function reset_course_progress( $course_id, $user_id ) {
		++$this->calls['reset_course'];
		return true;
	}

	public function count_course_completions( $user_id ) {
		return 2;
	}
	public function delete_course_completions( $user_id ) {
		++$this->calls['del_course_comp'];
		return 2;
	}

	public function count_lesson_completions( $user_id ) {
		return 5;
	}
	public function delete_lesson_completions( $user_id ) {
		++$this->calls['del_lesson'];
		return 5;
	}

	public function get_enrollment_ids( $user_id ) {
		return array( 1, 2 );
	}
	public function delete_enrollment( $enrollment_id ) {
		++$this->calls['del_enroll'];
		return true;
	}

	public function count_gradebook_results( $user_id ) {
		return 4;
	}
	public function delete_gradebook_results( $user_id ) {
		++$this->calls['del_grade'];
		return 4;
	}

	public function clear_learner_cache( $user_id ) {
		++$this->calls['cache'];
		return true;
	}
}

/**
 * Repository that simulates one failed enrollment deletion after earlier
 * pipeline steps have already succeeded.
 */
class TUC_Partial_Failure_Repo extends TUC_Fake_Repo {
	public function delete_enrollment( $enrollment_id ) {
		++$this->calls['del_enroll'];
		return 1 === (int) $enrollment_id;
	}
}

/**
 * Probe that exercises the REAL version-classification logic with a settable
 * version string (overrides only tutor_version()).
 */
class TUC_Version_Probe extends Tutor_User_Cleanup_Tutor_Data_Repository {
	public $v = '';
	public function tutor_version() {
		return $this->v;
	}
}

/**
 * Classify a version using the real repository logic.
 *
 * @param mixed  $ignored Unused (kept for call-site readability).
 * @param string $version Version string to test.
 * @return string
 */
function tuc_status_for( $ignored, $version ) {
	static $probe = null;
	if ( null === $probe ) {
		$probe = new TUC_Version_Probe();
	}
	$probe->v = $version;
	return $probe->get_version_support_status();
}

/**
 * Install a full settings array so Settings::get reads deterministic values and
 * logging stays off (no DB access during tests).
 *
 * @param array<string,mixed> $overrides Overrides.
 * @return void
 */
function tuc_install_settings( array $overrides = array() ) {
	$settings = array(
		'wipe_enrollments'     => true,
		'wipe_course_progress' => true,
		'wipe_quiz_attempts'   => true,
		'wipe_gradebook'       => true,
		'wipe_cache'           => true,
			'enable_logging'       => false,
		'log_retention_days'   => 180,
		'preserve_financial'   => true,
		'allow_unsupported_version' => false,
	);
	$settings = array_merge( $settings, $overrides );
	update_option( Tutor_User_Cleanup_Settings::OPTION_KEY, $settings );
}

/* ================================================================== */
/* 1. Settings                                                        */
/* ================================================================== */
echo "\n[1] Settings\n";
tuc_reset_stub_state();

$defaults = Tutor_User_Cleanup_Settings::defaults();
tuc_assert( true === $defaults['preserve_financial'], 'defaults preserve_financial is true' );

// preserve_financial can never be turned off, even if saved false.
$saved = Tutor_User_Cleanup_Settings::save(
	array(
		'wipe_enrollments'   => '1',
		'wipe_quiz_attempts' => '',      // unchecked.
		'preserve_financial' => '',      // attempt to disable.
		'log_retention_days' => '-9',    // should clamp to 0.
	)
);
tuc_assert_eq( true, $saved['preserve_financial'], 'save() forces preserve_financial true' );
tuc_assert_eq( true, $saved['wipe_enrollments'], 'save() keeps checked box true' );
tuc_assert_eq( false, $saved['wipe_quiz_attempts'], 'save() sets unchecked box false' );
tuc_assert_eq( 0, $saved['log_retention_days'], 'save() clamps negative retention to 0' );

tuc_install_settings( array( 'wipe_gradebook' => false ) );
tuc_assert_eq( true, Tutor_User_Cleanup_Settings::is_type_enabled( 'enrollments' ), 'is_type_enabled(enrollments) true' );
tuc_assert_eq( false, Tutor_User_Cleanup_Settings::is_type_enabled( 'gradebook' ), 'is_type_enabled(gradebook) false' );

/* ================================================================== */
/* 2. Cleanup guard (can_process)                                     */
/* ================================================================== */
echo "\n[2] can_process guard\n";
tuc_reset_stub_state();
tuc_install_settings();

$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

// Happy path.
$GLOBALS['_stub']['can']        = true;
$GLOBALS['_stub']['nonce_ok']   = true;
$GLOBALS['_stub']['valid_uids'] = array( 42 );
$_REQUEST['tutor_user_cleanup'] = '1';
$_REQUEST['_wpnonce']           = 'abc';

tuc_assert_eq( true, $svc->can_process( 42, 'delete-users' ), 'can_process passes with all conditions met' );

// No capability.
$GLOBALS['_stub']['can'] = false;
$r = $svc->can_process( 42 );
tuc_assert( is_wp_error( $r ) && 'tuc_no_cap' === $r->get_error_code(), 'blocks when capability missing' );
$GLOBALS['_stub']['can'] = true;

// Bad nonce.
$GLOBALS['_stub']['nonce_ok'] = false;
$r = $svc->can_process( 42 );
tuc_assert( is_wp_error( $r ) && 'tuc_bad_nonce' === $r->get_error_code(), 'blocks on bad nonce' );
$GLOBALS['_stub']['nonce_ok'] = true;

// Checkbox not ticked.
unset( $_REQUEST['tutor_user_cleanup'] );
$r = $svc->can_process( 42 );
tuc_assert( is_wp_error( $r ) && 'tuc_not_requested' === $r->get_error_code(), 'no-op when checkbox not ticked' );
$_REQUEST['tutor_user_cleanup'] = '1';

// Tutor inactive.
$repo->tutor_active = false;
$r = $svc->can_process( 42 );
tuc_assert( is_wp_error( $r ) && 'tuc_no_tutor' === $r->get_error_code(), 'blocks when Tutor inactive' );
$repo->tutor_active = true;

// Unknown user.
$r = $svc->can_process( 999 );
tuc_assert( is_wp_error( $r ) && 'tuc_no_user' === $r->get_error_code(), 'blocks when user does not exist' );

/* ================================================================== */
/* 3. wipe_user — dry-run counts, no deletions                        */
/* ================================================================== */
echo "\n[3] wipe_user dry-run\n";
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

$summary = $svc->wipe_user( 42, 'reset_learner', true );
tuc_assert_eq( 'success', $summary['result'], 'dry-run result success' );
tuc_assert_eq( 2, $summary['counts']['enrollments'], 'dry-run counts enrollments=2' );
tuc_assert_eq( 2, $summary['counts']['courses_reset'], 'dry-run counts courses_reset=2' );
tuc_assert_eq( 2, $summary['counts']['course_completions'], 'dry-run counts course_completions=2' );
tuc_assert_eq( 5, $summary['counts']['lesson_completions'], 'dry-run counts lesson_completions=5' );
tuc_assert_eq( 3, $summary['counts']['quiz_attempts'], 'dry-run counts quiz_attempts=3' );
tuc_assert_eq( 4, $summary['counts']['gradebook_results'], 'dry-run counts gradebook_results=4' );

$total_delete_calls = array_sum( $repo->calls );
tuc_assert_eq( 0, $total_delete_calls, 'dry-run performs ZERO delete/reset calls' );

/* ================================================================== */
/* 4. wipe_user — live deletions                                      */
/* ================================================================== */
echo "\n[4] wipe_user live\n";
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 'success', $summary['result'], 'live result success' );
tuc_assert_eq( 1, $repo->calls['delete_quiz'], 'live deletes quiz attempts once' );
tuc_assert_eq( 2, $repo->calls['reset_course'], 'live resets each enrolled course (2)' );
tuc_assert_eq( 1, $repo->calls['del_course_comp'], 'live sweeps course completions once' );
tuc_assert_eq( 1, $repo->calls['del_lesson'], 'live sweeps lesson completions once' );
tuc_assert_eq( 2, $repo->calls['del_enroll'], 'live deletes each enrollment post (2)' );
tuc_assert_eq( 1, $repo->calls['del_grade'], 'live deletes gradebook results once' );
tuc_assert_eq( 1, $repo->calls['cache'], 'live clears cache once' );
tuc_assert_eq( 2, $summary['operations']['enrollments']['requested'], 'live reports requested enrollment deletions' );
tuc_assert_eq( 2, $summary['operations']['enrollments']['deleted'], 'live reports actual enrollment deletions' );
tuc_assert_eq( 0, $summary['operations']['enrollments']['failed'], 'live reports zero failed enrollment deletions' );

/* ================================================================== */
/* 5. Dedupe within a request                                         */
/* ================================================================== */
echo "\n[5] dedupe\n";
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

$svc->wipe_user( 7, 'reset_learner', false );
$second = $svc->wipe_user( 7, 'reset_learner', false );
tuc_assert_eq( 'skipped', $second['result'], 'second wipe of same user in request is skipped' );
tuc_assert_eq( 1, $repo->calls['delete_quiz'], 'dedupe prevents a second round of deletions' );

// The same network user must still be processed independently on another site.
$GLOBALS['_stub']['current_blog'] = 2;
$third = $svc->wipe_user( 7, 'reset_learner', false );
tuc_assert_eq( 'success', $third['result'], 'same user is processed independently on another site' );
tuc_assert_eq( 2, $repo->calls['delete_quiz'], 'site-aware dedupe permits cleanup on the second site' );

/* ================================================================== */
/* 6. Type toggles honoured                                           */
/* ================================================================== */
echo "\n[6] type toggles\n";
tuc_reset_stub_state();
tuc_install_settings( array( 'wipe_quiz_attempts' => false, 'wipe_gradebook' => false ) );
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 0, $summary['counts']['quiz_attempts'], 'quiz disabled -> count stays 0' );
tuc_assert_eq( 0, $repo->calls['delete_quiz'], 'quiz disabled -> no quiz deletion' );
tuc_assert_eq( 0, $repo->calls['del_grade'], 'gradebook disabled -> no gradebook deletion' );
tuc_assert_eq( 2, $repo->calls['del_enroll'], 'enrollments still processed when enabled' );

/* ================================================================== */
/* 7. reset_learner permission gate                                   */
/* ================================================================== */
echo "\n[7] reset_learner permission\n";
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

$GLOBALS['_stub']['can'] = false;
$r = $svc->reset_learner( 42, true );
tuc_assert( is_wp_error( $r ), 'reset_learner blocked without permission' );

$GLOBALS['_stub']['can']        = true;
$GLOBALS['_stub']['valid_uids'] = array( 42 );
$r = $svc->reset_learner( 42, true );
tuc_assert( is_array( $r ) && 'success' === $r['result'], 'reset_learner allowed with permission' );

/* ================================================================== */
/* 8. Financial-data safety (source guarantee)                        */
/* ================================================================== */
echo "\n[8] financial safety\n";

/**
 * Strip PHP comments (line, block and docblock) from source so the scan below
 * inspects executable CODE only. Docblocks legitimately *name* the preserved
 * tables to document that they are never touched; those mentions must not trip
 * the guarantee. A real DELETE against one of these tables would still be code.
 *
 * @param string $src PHP source.
 * @return string Code with comments removed.
 */
function tuc_strip_php_comments( $src ) {
	$out = '';
	foreach ( token_get_all( $src ) as $tok ) {
		if ( is_array( $tok ) ) {
			if ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) {
				continue;
			}
			$out .= $tok[1];
		} else {
			$out .= $tok;
		}
	}
	return $out;
}

$repo_src    = tuc_strip_php_comments( file_get_contents( $base . '/includes/class-tutor-data-repository.php' ) );
$service_src = tuc_strip_php_comments( file_get_contents( $base . '/includes/class-cleanup-service.php' ) );

$forbidden = array( 'tutor_earnings', 'tutor_withdraws', 'tutor_orders', 'tutor_ordermeta', 'shop_order', 'wc_orders' );
foreach ( $forbidden as $needle ) {
	// These names must never appear inside a DELETE/DROP context. Simplest strong
	// guarantee: they must not appear in a deletion query at all. We check that the
	// substring is absent from any "DELETE"/"wp_delete"/"->delete(" usage.
	$in_repo    = ( false !== stripos( $repo_src, $needle ) );
	$in_service = ( false !== stripos( $service_src, $needle ) );
	tuc_assert( ! $in_repo && ! $in_service, "no reference to financial table/type '{$needle}'" );
}

// Quiz *definition* tables must never be deleted either.
tuc_assert( false === stripos( $repo_src, 'tutor_quiz_questions' ), 'never touches quiz definition table tutor_quiz_questions' );
tuc_assert( false === stripos( $repo_src, 'tutor_quiz_question_answers' ), 'never touches quiz definition table tutor_quiz_question_answers' );

// Reviews / Q&A comment types must not be deleted.
tuc_assert( false === stripos( $repo_src, 'tutor_course_rating' ), 'never deletes review comments (tutor_course_rating)' );
tuc_assert( false === stripos( $repo_src, 'tutor_q_and_a' ), 'never deletes Q&A comments (tutor_q_and_a)' );

/* ================================================================== */
/* 9. Version guard (pin Tutor LMS range)                             */
/* ================================================================== */
echo "\n[9] version guard\n";

// 9a. Newer-than-tested + no override + LIVE => blocked, no deletions.
tuc_reset_stub_state();
tuc_install_settings(); // allow_unsupported_version = false.
$repo = new TUC_Fake_Repo();
$repo->version_status = 'too_new';
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 'blocked', $summary['result'], 'live wipe BLOCKED on newer-than-tested version' );
tuc_assert_eq( 0, array_sum( $repo->calls ), 'blocked live wipe performs ZERO deletions' );
tuc_assert( '' !== $summary['message'], 'blocked result carries an explanatory message' );

// 9b. Newer-than-tested + DRY-RUN => allowed (preview), still no deletions.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$repo->version_status = 'too_new';
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$summary = $svc->wipe_user( 42, 'reset_learner', true );
tuc_assert_eq( 'success', $summary['result'], 'dry-run preview allowed even on untested version' );
tuc_assert_eq( 3, $summary['counts']['quiz_attempts'], 'dry-run still reports counts on untested version' );
tuc_assert_eq( 0, array_sum( $repo->calls ), 'dry-run on untested version performs ZERO deletions' );

// 9c. Newer-than-tested + override ON (setting) + LIVE => proceeds.
tuc_reset_stub_state();
tuc_install_settings( array( 'allow_unsupported_version' => true ) );
$repo = new TUC_Fake_Repo();
$repo->version_status = 'too_new';
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 'success', $summary['result'], 'override lets live wipe proceed on untested version' );
tuc_assert_eq( 2, $repo->calls['del_enroll'], 'override live wipe actually deletes enrollments' );

// 9d. Older-than-min + no override + LIVE => blocked.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$repo->version_status = 'too_old';
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 'blocked', $summary['result'], 'live wipe BLOCKED on older-than-minimum version' );

// 9e. Undetectable version + no override + LIVE => fail closed.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$repo->version_status = 'unknown';
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 'blocked', $summary['result'], 'live wipe BLOCKED when Tutor version is unknown' );
tuc_assert_eq( 0, array_sum( $repo->calls ), 'unknown-version block performs ZERO deletions' );

// 9f. Real repository classifies versions correctly against the pinned range.
tuc_reset_stub_state();
$real = new Tutor_User_Cleanup_Tutor_Data_Repository();
tuc_assert_eq( 'ok', tuc_status_for( $real, '4.0.1' ), 'real repo: 4.0.1 (== tested) is ok' );
tuc_assert_eq( 'ok', tuc_status_for( $real, '3.9.0' ), 'real repo: 3.9.0 (in range) is ok' );
tuc_assert_eq( 'too_new', tuc_status_for( $real, '4.1.0' ), 'real repo: 4.1.0 (> tested) is too_new' );
tuc_assert_eq( 'too_old', tuc_status_for( $real, '1.9.9' ), 'real repo: 1.9.9 (< min) is too_old' );
tuc_assert_eq( 'unknown', tuc_status_for( $real, '' ), 'real repo: empty version is unknown' );

/* ================================================================== */
/* 10. Settings sanitization — edge cases                             */
/* ================================================================== */
echo "\n[10] settings edge cases\n";
tuc_reset_stub_state();

// Retention: non-numeric, float, zero, missing.
$r = Tutor_User_Cleanup_Settings::save( array( 'log_retention_days' => 'abc' ) );
tuc_assert_eq( 0, $r['log_retention_days'], 'non-numeric retention becomes 0 (keep forever)' );

$r = Tutor_User_Cleanup_Settings::save( array( 'log_retention_days' => '30.9' ) );
tuc_assert_eq( 30, $r['log_retention_days'], 'float retention truncates to int' );

$r = Tutor_User_Cleanup_Settings::save( array( 'log_retention_days' => '0' ) );
tuc_assert_eq( 0, $r['log_retention_days'], 'explicit 0 retention preserved' );

// Missing key falls back to the DEFAULT, not to 0 — guards against a partial
// form submission silently disabling log purging.
$r = Tutor_User_Cleanup_Settings::save( array() );
tuc_assert_eq( 180, $r['log_retention_days'], 'missing retention falls back to default (180)' );

// Unknown/injected keys are dropped entirely.
$r = Tutor_User_Cleanup_Settings::save( array( 'evil_key' => '1', 'wipe_enrollments' => '1' ) );
tuc_assert( ! array_key_exists( 'evil_key', $r ), 'unknown keys are not persisted' );

// An empty submission turns every toggle off but keeps the financial guard on.
tuc_assert_eq( false, $r['wipe_quiz_attempts'], 'unsubmitted toggle saved as false' );
tuc_assert_eq( true, $r['preserve_financial'], 'preserve_financial still forced true' );

// is_type_enabled on an unknown type is false (never wipes something unnamed).
tuc_install_settings();
tuc_assert_eq( false, Tutor_User_Cleanup_Settings::is_type_enabled( 'made_up_type' ), 'unknown data type is never enabled' );

// A corrupted (non-array) option must not fatal — defaults are used.
update_option( Tutor_User_Cleanup_Settings::OPTION_KEY, 'corrupted-string' );
$all = Tutor_User_Cleanup_Settings::all();
tuc_assert( is_array( $all ) && true === $all['preserve_financial'], 'corrupted option falls back to defaults' );

/* ================================================================== */
/* 11. Guard — multisite context and malformed requests               */
/* ================================================================== */
echo "\n[11] guard: multisite + malformed requests\n";
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );

$GLOBALS['_stub']['valid_uids'] = array( 42 );
$_REQUEST['tutor_user_cleanup'] = '1';
$_REQUEST['_wpnonce']           = 'abc';

// Network context uses the action WordPress core verifies in network/users.php.
tuc_assert_eq( true, $svc->can_process( 42, 'ms-users-delete' ), 'can_process passes in multisite context' );
tuc_assert_eq( 'ms-users-delete', $GLOBALS['_stub']['last_nonce_action'], 'network cleanup verifies the WordPress core nonce action' );

// A completely missing nonce is rejected (not just an invalid one).
unset( $_REQUEST['_wpnonce'] );
$r = $svc->can_process( 42 );
tuc_assert( is_wp_error( $r ) && 'tuc_bad_nonce' === $r->get_error_code(), 'missing nonce is rejected' );
$_REQUEST['_wpnonce'] = 'abc';

// user_id 0 / non-numeric never resolves to a user.
$r = $svc->can_process( 0 );
tuc_assert( is_wp_error( $r ) && 'tuc_no_user' === $r->get_error_code(), 'user_id 0 rejected' );
$r = $svc->can_process( 'abc' );
tuc_assert( is_wp_error( $r ) && 'tuc_no_user' === $r->get_error_code(), 'non-numeric user_id rejected' );

// A checkbox value of "0" counts as NOT requested (empty() semantics).
$_REQUEST['tutor_user_cleanup'] = '0';
$r = $svc->can_process( 42 );
tuc_assert( is_wp_error( $r ) && 'tuc_not_requested' === $r->get_error_code(), 'checkbox value "0" is treated as unticked' );

/* ================================================================== */
/* 12. handle_user_deletion — the real delete-user entry point         */
/* ================================================================== */
echo "\n[12] handle_user_deletion\n";

// 12a. Deletion from elsewhere (REST/WP-CLI/WooCommerce) — no checkbox, so the
//      plugin must do NOTHING. This is the single most important safety case.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$GLOBALS['_stub']['valid_uids'] = array( 42 );
$_REQUEST['_wpnonce']           = 'abc';
// note: no $_REQUEST['tutor_user_cleanup'].

$r = $svc->handle_user_deletion( 42 );
tuc_assert( is_wp_error( $r ) && 'tuc_not_requested' === $r->get_error_code(), 'delete without checkbox is a no-op' );
tuc_assert_eq( 0, array_sum( $repo->calls ), 'no-op deletion performs ZERO deletions' );

// 12b. With the checkbox ticked it runs a live wipe.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$GLOBALS['_stub']['valid_uids'] = array( 42 );
$_REQUEST['_wpnonce']           = 'abc';
$_REQUEST['tutor_user_cleanup'] = '1';

$r = $svc->handle_user_deletion( 42 );
tuc_assert_eq( 'success', $r['result'], 'ticked deletion runs the wipe' );
tuc_assert_eq( false, $r['dry_run'], 'ticked deletion runs LIVE by default' );
tuc_assert_eq( 2, $repo->calls['del_enroll'], 'ticked deletion deletes enrollments' );

// 12c. Delete-user cleanup is always live; preview belongs to the separate
//      preview/reset flows and must never leave Tutor rows behind a deleted user.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$GLOBALS['_stub']['valid_uids']         = array( 42 );
$_REQUEST['_wpnonce']                   = 'abc';
$_REQUEST['tutor_user_cleanup']         = '1';
$_REQUEST['tutor_user_cleanup_dry_run'] = '1';

$r = $svc->handle_user_deletion( 42 );
tuc_assert_eq( false, $r['dry_run'], 'per-request dry-run flag cannot weaken delete-user cleanup' );
tuc_assert_eq( 2, $repo->calls['del_enroll'], 'delete-user cleanup remains live with a forged dry-run flag' );

// 12d. A legacy default_dry_run option must also be ignored in delete-user flow.
tuc_reset_stub_state();
tuc_install_settings( array( 'default_dry_run' => true ) );
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$GLOBALS['_stub']['valid_uids'] = array( 42 );
$_REQUEST['_wpnonce']           = 'abc';
$_REQUEST['tutor_user_cleanup'] = '1';

$r = $svc->handle_user_deletion( 42 );
tuc_assert_eq( false, $r['dry_run'], 'legacy default_dry_run cannot weaken delete-user cleanup' );
tuc_assert_eq( 2, $repo->calls['del_enroll'], 'legacy dry-run setting still performs requested live cleanup' );

// 12e. Network deletion iterates every user site and restores the original site.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$GLOBALS['_stub']['valid_uids'] = array( 42 );
$GLOBALS['_stub']['user_blogs'] = array( 2, 3 );
$_REQUEST['_wpnonce']           = 'abc';
$_REQUEST['tutor_user_cleanup'] = '1';

$results = $svc->handle_network_user_deletion( 42 );
tuc_assert_eq( array( 2, 3 ), array_keys( $results ), 'network cleanup returns one result per user site' );
tuc_assert_eq( 4, $repo->calls['del_enroll'], 'network cleanup wipes enrollments on both sites' );
tuc_assert_eq( 1, get_current_blog_id(), 'network cleanup restores the original site context' );
tuc_assert_eq( 'ms-users-delete', $GLOBALS['_stub']['last_nonce_action'], 'network cleanup uses the WordPress core network nonce action' );

/* ================================================================== */
/* 13. Error isolation, hooks, and remaining version paths            */
/* ================================================================== */
echo "\n[13] error isolation, hooks, version paths\n";

/**
 * Repo whose first pipeline step explodes, to prove one failure cannot abort
 * the whole bulk run (or surface as a PHP fatal).
 */
class TUC_Exploding_Repo extends TUC_Fake_Repo {
	public function get_quiz_attempt_ids( $user_id ) {
		throw new RuntimeException( 'simulated DB failure' );
	}
}

tuc_reset_stub_state();
tuc_install_settings();
$boom = new TUC_Exploding_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $boom );

$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 'partial', $summary['result'], 'thrown exception is isolated and reported as partial' );
tuc_assert( false !== strpos( $summary['message'], 'simulated DB failure' ), 'error message is preserved' );
tuc_assert_eq( 2, $boom->calls['del_enroll'], 'later pipeline steps continue after an earlier step throws' );

// A second, healthy user in the same request still processes fine.
$summary2 = $svc->wipe_user( 43, 'reset_learner', false );
tuc_assert_eq( 'partial', $summary2['result'], 'each user is isolated (same failing repo remains partial)' );

tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$ok   = $svc->wipe_user( 44, 'reset_learner', false );
tuc_assert_eq( 'success', $ok['result'], 'a healthy repo is unaffected by an earlier failure' );

// 13b. Extension hooks fire with the documented arguments.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$svc->wipe_user( 42, 'reset_learner', false );

$before = tuc_actions_fired( 'tutor_user_cleanup_before_user' );
$after  = tuc_actions_fired( 'tutor_user_cleanup_after_user' );
tuc_assert_eq( 1, count( $before ), 'before_user hook fired once' );
tuc_assert_eq( 1, count( $after ), 'after_user hook fired once' );
tuc_assert_eq( 42, $before[0][0], 'before_user receives the user id' );
tuc_assert_eq( 'reset_learner', $before[0][2], 'before_user receives the context' );
tuc_assert_eq( 42, $after[0][0], 'after_user receives the user id' );
tuc_assert( is_array( $after[0][1] ) && 2 === $after[0][1]['enrollments'], 'after_user receives the counts array' );
tuc_assert_eq( false, $after[0][2], 'after_user receives the dry-run flag' );

// A BLOCKED run must not fire the extension hooks at all.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$repo->version_status = 'too_new';
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 0, count( tuc_actions_fired( 'tutor_user_cleanup_after_user' ) ), 'blocked run fires no after_user hook' );

// 13c. The developer filter can lift the version block (setting stays off).
tuc_reset_stub_state();
tuc_install_settings( array( 'allow_unsupported_version' => false ) );
add_filter( 'tutor_user_cleanup_allow_unsupported_version', '__return_true' );
$repo = new TUC_Fake_Repo();
$repo->version_status = 'too_new';
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 'success', $summary['result'], 'filter override lifts the version block' );
tuc_assert_eq( 2, $repo->calls['del_enroll'], 'filter-overridden run actually deletes' );

// 13d. A developer override may explicitly allow an unknown version.
tuc_reset_stub_state();
tuc_install_settings( array( 'allow_unsupported_version' => true ) );
$repo = new TUC_Fake_Repo();
$repo->version_status = 'unknown';
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 'success', $summary['result'], 'override permits live cleanup on unknown version' );
tuc_assert( '' !== $summary['message'], 'unknown version still carries a caution message' );

// 13e. Every data type disabled => a completely inert run.
tuc_reset_stub_state();
tuc_install_settings(
	array(
		'wipe_enrollments'     => false,
		'wipe_course_progress' => false,
		'wipe_quiz_attempts'   => false,
		'wipe_gradebook'       => false,
		'wipe_cache'           => false,
	)
);
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 'success', $summary['result'], 'all-types-disabled run still succeeds' );
tuc_assert_eq( 0, array_sum( $repo->calls ), 'all-types-disabled run touches nothing' );
tuc_assert_eq( 0, array_sum( $summary['counts'] ), 'all-types-disabled run reports zero counts' );

// 13f. reset_learner is allowed for an admin holding only manage_options.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$GLOBALS['_stub']['can']        = true;
$GLOBALS['_stub']['valid_uids'] = array( 42 );
// reset_learner does not require the checkbox/nonce — it is an explicit action.
unset( $_REQUEST['tutor_user_cleanup'] );
$r = $svc->reset_learner( 42, false );
tuc_assert( is_array( $r ) && 'success' === $r['result'], 'reset_learner needs no delete-user checkbox' );

// 13g. reset_learner is blocked when Tutor is inactive.
tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Fake_Repo();
$repo->tutor_active = false;
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$GLOBALS['_stub']['valid_uids'] = array( 42 );
$r = $svc->reset_learner( 42, false );
tuc_assert( is_wp_error( $r ) && 'tuc_no_tutor' === $r->get_error_code(), 'reset_learner blocked when Tutor inactive' );

/* ================================================================== */
/* 14. Partial results, retention time, and uninstall coverage         */
/* ================================================================== */
echo "\n[14] partial results + maintenance safety\n";

tuc_reset_stub_state();
tuc_install_settings();
$repo = new TUC_Partial_Failure_Repo();
$svc  = new Tutor_User_Cleanup_Cleanup_Service( $repo );
$summary = $svc->wipe_user( 42, 'reset_learner', false );
tuc_assert_eq( 'partial', $summary['result'], 'mixed deletion outcomes are reported as partial' );
tuc_assert_eq( 2, $summary['operations']['enrollments']['requested'], 'partial result keeps requested count' );
tuc_assert_eq( 1, $summary['operations']['enrollments']['deleted'], 'partial result reports actual deleted count' );
tuc_assert_eq( 1, $summary['operations']['enrollments']['failed'], 'partial result reports failed count' );

$timestamp = 1735689600; // 2025-01-01 00:00:00 UTC.
tuc_assert_eq(
	'2025-01-01 00:00:00',
	Tutor_User_Cleanup_Logger::utc_mysql_time( $timestamp ),
	'logger formats retention timestamps in UTC'
);

$uninstall_src = tuc_strip_php_comments( file_get_contents( $base . '/uninstall.php' ) );
tuc_assert(
	(bool) preg_match( "/get_sites\\s*\\(\\s*array\\s*\\([^)]*'number'\\s*=>\\s*0/s", $uninstall_src ),
	'uninstall requests every multisite site instead of the default first 100'
);

$admin_src = tuc_strip_php_comments( file_get_contents( $base . '/includes/class-admin-ui.php' ) );
tuc_assert(
	false !== strpos( $admin_src, 'is_network_admin()' ),
	'network users checkbox is enabled from network-admin context, not a guessed hook suffix'
);

/* ================================================================== */
/* Summary                                                            */
/* ================================================================== */
echo "\n--------------------------------------------------\n";
printf( "Ran %d assertions, %d failed.\n", $GLOBALS['tuc_tests'], $GLOBALS['tuc_failed'] );
echo "--------------------------------------------------\n";

exit( $GLOBALS['tuc_failed'] > 0 ? 1 : 0 );
