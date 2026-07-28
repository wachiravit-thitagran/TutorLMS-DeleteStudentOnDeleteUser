<?php
/**
 * Plugin Name:       Tutor User Cleanup
 * Plugin URI:        https://github.com/wachiravit/TutorLMS-DeleteStudentOnDeleteUser
 * Description:        Adds an option to the WordPress "Delete Users" screen to also wipe a user's Tutor LMS learning data (enrollments, progress, completion, quiz attempts &amp; answers) — while preserving orders and other financial records — so the same email can re-register as a fresh learner. Includes a separate "Reset learner" action, dry-run mode, logging and multisite support.
 * Version:           1.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Wachiravit
 * Author URI:        https://github.com/wachiravit
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tutor-user-cleanup
 * Domain Path:       /languages
 *
 * @package TutorUserCleanup
 */

defined( 'ABSPATH' ) || exit;

/*
|--------------------------------------------------------------------------
| Constants
|--------------------------------------------------------------------------
*/
define( 'TUTOR_USER_CLEANUP_VERSION', '1.1.0' );
define( 'TUTOR_USER_CLEANUP_FILE', __FILE__ );
define( 'TUTOR_USER_CLEANUP_PATH', plugin_dir_path( __FILE__ ) );
define( 'TUTOR_USER_CLEANUP_URL', plugin_dir_url( __FILE__ ) );
define( 'TUTOR_USER_CLEANUP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Supported Tutor LMS version range.
 *
 * The learner-data model (tutor_enrolled CPT, tutor_quiz_attempts /
 * tutor_quiz_attempt_answers tables, course_completed comments, lesson-completion
 * usermeta) was validated against this range. If the installed Tutor LMS version
 * falls OUTSIDE this range — most importantly, if it is NEWER than the tested
 * version — the database structure may have changed, so live deletion is blocked
 * by default to avoid removing the wrong records. Dry-run/preview stays available,
 * and an admin who has verified compatibility can override the block.
 *
 * Bump TESTED after re-validating the data model against a newer Tutor release.
 */
define( 'TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION', '2.0.0' );
define( 'TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION', '4.0.1' );

/*
|--------------------------------------------------------------------------
| Autoloading of plugin classes
|--------------------------------------------------------------------------
| Simple PSR-ish loader mapping class name -> includes/class-*.php file.
*/
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Tutor_User_Cleanup' ) ) {
			return;
		}

		// Tutor_User_Cleanup_Cleanup_Service -> class-cleanup-service.php.
		$relative = strtolower( str_replace( array( 'Tutor_User_Cleanup_', '_' ), array( '', '-' ), $class ) );

		// Special case: the top-level "Tutor_User_Cleanup_Plugin" bootstrap class.
		if ( 'plugin' === $relative ) {
			$file = TUTOR_USER_CLEANUP_PATH . 'includes/class-plugin.php';
		} else {
			$file = TUTOR_USER_CLEANUP_PATH . 'includes/class-' . $relative . '.php';
		}

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/*
|--------------------------------------------------------------------------
| Activation / Deactivation
|--------------------------------------------------------------------------
*/
register_activation_hook(
	__FILE__,
	static function () {
		require_once TUTOR_USER_CLEANUP_PATH . 'includes/class-logger.php';
		require_once TUTOR_USER_CLEANUP_PATH . 'includes/class-settings.php';

		Tutor_User_Cleanup_Logger::install();
		Tutor_User_Cleanup_Settings::install_defaults();

		update_option( 'tutor_user_cleanup_version', TUTOR_USER_CLEANUP_VERSION );
	}
);

/*
|--------------------------------------------------------------------------
| Boot
|--------------------------------------------------------------------------
*/
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'tutor-user-cleanup', false, dirname( TUTOR_USER_CLEANUP_BASENAME ) . '/languages' );
		Tutor_User_Cleanup_Plugin::instance()->init();
	}
);
