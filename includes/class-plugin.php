<?php
/**
 * Main plugin controller — wires all hooks.
 *
 * @package TutorUserCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Tutor_User_Cleanup_Plugin
 */
final class Tutor_User_Cleanup_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Tutor_User_Cleanup_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin UI handler.
	 *
	 * @var Tutor_User_Cleanup_Admin_UI
	 */
	private $admin_ui;

	/**
	 * Reset-learner handler.
	 *
	 * @var Tutor_User_Cleanup_Reset_Learner
	 */
	private $reset_learner;

	/**
	 * Cleanup service.
	 *
	 * @var Tutor_User_Cleanup_Cleanup_Service
	 */
	private $service;

	/**
	 * Singleton accessor.
	 *
	 * @return Tutor_User_Cleanup_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function init() {
		$this->service = new Tutor_User_Cleanup_Cleanup_Service();

		// Cleanup triggers (single-site + multisite).
		add_action( 'delete_user', array( $this, 'on_delete_user' ), 10, 3 );
		add_action( 'wpmu_delete_user', array( $this, 'on_wpmu_delete_user' ), 10, 1 );

		// Log housekeeping.
		add_action( 'tutor_user_cleanup_daily', array( 'Tutor_User_Cleanup_Logger', 'purge_expired' ) );
		if ( ! wp_next_scheduled( 'tutor_user_cleanup_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tutor_user_cleanup_daily' );
		}

		// Admin surfaces (only in admin context).
		if ( is_admin() ) {
			$this->admin_ui      = new Tutor_User_Cleanup_Admin_UI( $this->service );
			$this->reset_learner = new Tutor_User_Cleanup_Reset_Learner( $this->service );
			$this->admin_ui->init();
			$this->reset_learner->init();
		}
	}

	/**
	 * Single-site delete: runs during wp_delete_user() before data is removed.
	 *
	 * @param int      $user_id  Deleted user ID.
	 * @param int|null $reassign Reassign target (unused).
	 * @param WP_User  $user     User object (unused).
	 * @return void
	 */
	public function on_delete_user( $user_id, $reassign = null, $user = null ) {
		unset( $reassign, $user );
		$this->service->handle_user_deletion( $user_id, 'delete-users' );
	}

	/**
	 * Multisite network delete: runs during wpmu_delete_user().
	 *
	 * @param int $user_id Deleted user ID.
	 * @return void
	 */
	public function on_wpmu_delete_user( $user_id ) {
		$this->service->handle_network_user_deletion( $user_id );
	}

	/**
	 * Expose the service (used by admin handlers).
	 *
	 * @return Tutor_User_Cleanup_Cleanup_Service
	 */
	public function service() {
		return $this->service;
	}
}
