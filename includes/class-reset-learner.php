<?php
/**
 * "Reset learner" — wipe a user's Tutor learning data WITHOUT deleting the
 * WordPress account. Exposed as a per-row action and a bulk action on the
 * Users screen, always routed through a confirmation screen that shows a
 * dry-run preview before anything is removed.
 *
 * @package TutorUserCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Tutor_User_Cleanup_Reset_Learner
 */
class Tutor_User_Cleanup_Reset_Learner {

	const PAGE_SLUG   = 'tutor-user-cleanup-reset';
	const DO_ACTION   = 'tutor_user_cleanup_reset_do';
	const BULK_ACTION = 'tutor_user_cleanup_reset';

	/**
	 * Cleanup service.
	 *
	 * @var Tutor_User_Cleanup_Cleanup_Service
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param Tutor_User_Cleanup_Cleanup_Service $service Cleanup service.
	 */
	public function __construct( Tutor_User_Cleanup_Cleanup_Service $service ) {
		$this->service = $service;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Per-row action.
		add_filter( 'user_row_actions', array( $this, 'add_row_action' ), 10, 2 );

		// Bulk action on the Users list.
		add_filter( 'bulk_actions-users', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'handle_bulk_action' ), 10, 3 );

		// Hidden confirmation page + processing.
		add_action( 'admin_menu', array( $this, 'register_hidden_page' ) );
		add_action( 'admin_post_' . self::DO_ACTION, array( $this, 'handle_confirmed_reset' ) );

		// Result notice.
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
	}

	/**
	 * Add "Reset Tutor learner data" to each user's row actions.
	 *
	 * @param string[] $actions Existing actions.
	 * @param WP_User  $user    User object.
	 * @return string[]
	 */
	public function add_row_action( $actions, $user ) {
		if ( ! current_user_can( 'delete_users' ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'user' => (int) $user->ID,
				),
				admin_url( 'admin.php' )
			),
			'tutor_reset_confirm_' . (int) $user->ID
		);

		$actions['tutor_user_cleanup_reset'] = sprintf(
			'<a href="%s" class="tutor-user-cleanup-reset-link">%s</a>',
			esc_url( $url ),
			esc_html__( 'Reset Tutor data', 'tutor-user-cleanup' )
		);
		return $actions;
	}

	/**
	 * Register the "Reset Tutor learner data" bulk action.
	 *
	 * @param array<string,string> $actions Existing bulk actions.
	 * @return array<string,string>
	 */
	public function add_bulk_action( $actions ) {
		if ( current_user_can( 'delete_users' ) ) {
			$actions[ self::BULK_ACTION ] = __( 'Reset Tutor learner data', 'tutor-user-cleanup' );
		}
		return $actions;
	}

	/**
	 * Handle the bulk action by redirecting to the confirmation screen.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Selected action.
	 * @param int[]  $user_ids    Selected user IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $doaction, $user_ids ) {
		if ( self::BULK_ACTION !== $doaction ) {
			return $redirect_to;
		}
		if ( ! current_user_can( 'delete_users' ) ) {
			return $redirect_to;
		}

		$user_ids = array_filter( array_map( 'absint', (array) $user_ids ) );
		if ( empty( $user_ids ) ) {
			return $redirect_to;
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'users' => implode( ',', $user_ids ),
				),
				admin_url( 'admin.php' )
			),
			'tutor_reset_confirm_bulk'
		);
	}

	/**
	 * Register a hidden admin page that renders the confirmation screen.
	 *
	 * @return void
	 */
	public function register_hidden_page() {
		add_submenu_page(
			'', // hidden (no menu parent).
			__( 'Reset Tutor learner data', 'tutor-user-cleanup' ),
			__( 'Reset Tutor learner data', 'tutor-user-cleanup' ),
			'delete_users',
			self::PAGE_SLUG,
			array( $this, 'render_confirm_screen' )
		);
	}

	/**
	 * Resolve and validate the target user IDs + nonce for the confirm screen.
	 *
	 * @return int[]|WP_Error
	 */
	private function resolve_targets() {
		if ( ! current_user_can( 'delete_users' ) ) {
			return new WP_Error( 'tuc_no_cap', __( 'Insufficient permissions.', 'tutor-user-cleanup' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce validated explicitly below.
		$single = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$bulk   = isset( $_GET['users'] ) ? sanitize_text_field( wp_unslash( $_GET['users'] ) ) : '';
		$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $single ) {
			if ( ! wp_verify_nonce( $nonce, 'tutor_reset_confirm_' . $single ) ) {
				return new WP_Error( 'tuc_bad_nonce', __( 'Security check failed.', 'tutor-user-cleanup' ) );
			}
			return array( $single );
		}

		if ( '' !== $bulk ) {
			if ( ! wp_verify_nonce( $nonce, 'tutor_reset_confirm_bulk' ) ) {
				return new WP_Error( 'tuc_bad_nonce', __( 'Security check failed.', 'tutor-user-cleanup' ) );
			}
			$ids = array_filter( array_map( 'absint', explode( ',', $bulk ) ) );
			return array_values( $ids );
		}

		return new WP_Error( 'tuc_no_target', __( 'No user specified.', 'tutor-user-cleanup' ) );
	}

	/**
	 * Render the confirmation screen with a dry-run preview.
	 *
	 * @return void
	 */
	public function render_confirm_screen() {
		$targets = $this->resolve_targets();
		if ( is_wp_error( $targets ) ) {
			wp_die( esc_html( $targets->get_error_message() ) );
		}
		if ( is_multisite() ) {
			wp_die( esc_html( Tutor_User_Cleanup_Cleanup_Service::multisite_block_message() ) );
			return;
		}

		$repo           = new Tutor_User_Cleanup_Tutor_Data_Repository();
		$version_status = $repo->get_version_support_status();
		$override_on    = (bool) Tutor_User_Cleanup_Settings::get( 'allow_unsupported_version', false );

		// Build a combined dry-run preview.
		$totals = array();
		$names  = array();
		foreach ( $targets as $uid ) {
			$user = get_userdata( $uid );
			if ( ! $user ) {
				continue;
			}
			$names[] = $user->user_login . ' (#' . $uid . ')';

			$summary = $this->service->reset_learner( $uid, true );
			if ( is_wp_error( $summary ) ) {
				continue;
			}
			foreach ( $summary['counts'] as $key => $value ) {
				$totals[ $key ] = ( isset( $totals[ $key ] ) ? $totals[ $key ] : 0 ) + (int) $value;
			}
		}
		?>
		<div class="wrap tutor-user-cleanup-confirm">
			<h1><?php esc_html_e( 'Reset Tutor learner data', 'tutor-user-cleanup' ); ?></h1>

			<p>
				<?php esc_html_e( 'You are about to remove Tutor LMS learning data for:', 'tutor-user-cleanup' ); ?>
				<strong><?php echo esc_html( implode( ', ', $names ) ); ?></strong>
			</p>
			<p class="description">
				<?php esc_html_e( 'The WordPress account(s) will NOT be deleted — only enrollments, progress, completion and quiz attempts are removed. Orders and financial data are preserved.', 'tutor-user-cleanup' ); ?>
			</p>

			<?php if ( 'ok' !== $version_status && ! $override_on ) : ?>
				<div class="notice notice-error inline">
					<p>
						<strong><?php esc_html_e( 'Blocked by version guard:', 'tutor-user-cleanup' ); ?></strong>
						<?php
						printf(
							/* translators: 1: detected version, 2: tested-up-to version. */
							esc_html__( 'Tutor LMS %1$s is outside the tested range (up to %2$s), so live reset is disabled to protect your data. Preview still works. Enable the override under Tools → Tutor User Cleanup only after verifying compatibility.', 'tutor-user-cleanup' ),
							esc_html( $repo->tutor_version() ? $repo->tutor_version() : __( 'unknown', 'tutor-user-cleanup' ) ),
							esc_html( TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Preview (nothing removed yet)', 'tutor-user-cleanup' ); ?></h2>
			<?php echo wp_kses_post( $this->render_totals_table( $totals ) ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'This cannot be undone. Reset the learning data now?', 'tutor-user-cleanup' ) ); ?>');">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::DO_ACTION ); ?>" />
				<input type="hidden" name="users" value="<?php echo esc_attr( implode( ',', $targets ) ); ?>" />
				<?php wp_nonce_field( self::DO_ACTION ); ?>
				<p class="submit">
					<?php
					$submit_attrs = ( 'ok' !== $version_status && ! $override_on ) ? array( 'disabled' => 'disabled' ) : array();
					submit_button( __( 'Yes, reset learning data', 'tutor-user-cleanup' ), 'delete', 'submit', false, $submit_attrs );
					?>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php esc_html_e( 'Cancel', 'tutor-user-cleanup' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Process the confirmed reset (live) and redirect back with a notice.
	 *
	 * @return void
	 */
	public function handle_confirmed_reset() {
		if ( ! current_user_can( 'delete_users' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tutor-user-cleanup' ) );
		}
		check_admin_referer( self::DO_ACTION );

		$raw     = isset( $_POST['users'] ) ? sanitize_text_field( wp_unslash( $_POST['users'] ) ) : '';
		$targets = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

		$ok    = 0;
		$fail  = 0;
		foreach ( $targets as $uid ) {
			$summary = $this->service->reset_learner( $uid, false ); // live.
			$is_success = ! is_wp_error( $summary ) && isset( $summary['result'] ) && 'success' === $summary['result'];
			if ( $is_success ) {
				++$ok;
			} else {
				++$fail; // includes 'blocked' (untested version) and 'error'.
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'tuc_reset_ok'   => $ok,
					'tuc_reset_fail' => $fail,
				),
				admin_url( 'users.php' )
			)
		);
		exit;
	}

	/**
	 * Show a success/failure notice after a reset.
	 *
	 * @return void
	 */
	public function maybe_render_notice() {
		if ( ! isset( $_GET['tuc_reset_ok'] ) && ! isset( $_GET['tuc_reset_fail'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$ok   = isset( $_GET['tuc_reset_ok'] ) ? absint( $_GET['tuc_reset_ok'] ) : 0;   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fail = isset( $_GET['tuc_reset_fail'] ) ? absint( $_GET['tuc_reset_fail'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$class = $fail > 0 ? 'notice-warning' : 'notice-success';
		$msg   = sprintf(
			/* translators: 1: success count, 2: failure count. */
			__( 'Tutor learner data reset: %1$d succeeded, %2$d failed.', 'tutor-user-cleanup' ),
			$ok,
			$fail
		);
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $msg ) );
	}

	/**
	 * Render a totals table from a counts array.
	 *
	 * @param array<string,int> $totals Totals.
	 * @return string HTML.
	 */
	private function render_totals_table( array $totals ) {
		$labels = array(
			'enrollments'        => __( 'Enrollments', 'tutor-user-cleanup' ),
			'courses_reset'      => __( 'Courses with progress', 'tutor-user-cleanup' ),
			'course_completions' => __( 'Course completions', 'tutor-user-cleanup' ),
			'lesson_completions' => __( 'Lesson completions', 'tutor-user-cleanup' ),
			'quiz_attempts'      => __( 'Quiz attempts', 'tutor-user-cleanup' ),
			'gradebook_results'  => __( 'Gradebook results', 'tutor-user-cleanup' ),
		);

		$has_any = false;
		foreach ( $totals as $v ) {
			if ( (int) $v > 0 ) {
				$has_any = true;
				break;
			}
		}
		if ( ! $has_any ) {
			return '<p>' . esc_html__( 'No learning data found for the selected user(s).', 'tutor-user-cleanup' ) . '</p>';
		}

		$rows = '';
		foreach ( $labels as $key => $label ) {
			$val   = isset( $totals[ $key ] ) ? (int) $totals[ $key ] : 0;
			$rows .= '<tr><td>' . esc_html( $label ) . '</td><td><strong>' . esc_html( (string) $val ) . '</strong></td></tr>';
		}

		return '<table class="widefat striped" style="max-width:420px"><tbody>' . $rows . '</tbody></table>';
	}
}
