<?php
/**
 * Admin UI: the checkbox on the delete-user screen, asset loading, an AJAX
 * preview endpoint, and the settings + log screen.
 *
 * @package TutorUserCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Tutor_User_Cleanup_Admin_UI
 */
class Tutor_User_Cleanup_Admin_UI {

	const PREVIEW_NONCE = 'tutor_user_cleanup_preview';

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
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Checkbox on the single-site delete confirmation form.
		add_action( 'delete_user_form', array( $this, 'render_delete_user_field' ), 10, 2 );

		// Assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// AJAX preview (dry-run counts).
		add_action( 'wp_ajax_tutor_user_cleanup_preview', array( $this, 'ajax_preview' ) );

		// Settings + log page.
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );

		// Settings shortcut on the plugins screen.
		add_filter( 'plugin_action_links_' . TUTOR_USER_CLEANUP_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Render the opt-in checkbox and warnings inside the delete-user form.
	 *
	 * @param WP_User $current_user Current user.
	 * @param int[]   $userids      IDs being deleted.
	 * @return void
	 */
	public function render_delete_user_field( $current_user, $userids ) {
		unset( $current_user );
		$userids = array_map( 'absint', (array) $userids );

		$repo         = new Tutor_User_Cleanup_Tutor_Data_Repository();
		$tutor_active = $repo->is_tutor_active();

		$version_status  = $repo->get_version_support_status();
		$version_ok      = ( 'ok' === $version_status );
		$override_on     = (bool) Tutor_User_Cleanup_Settings::get( 'allow_unsupported_version', false );

		// Detect if any selected account authored courses (instructor) — warn, don't block.
		$has_instructor = false;
		foreach ( $userids as $uid ) {
			if ( $repo->is_instructor( $uid ) ) {
				$has_instructor = true;
				break;
			}
		}

		$default_checked = false; // opt-in must be explicit.
		?>
		<fieldset class="tutor-user-cleanup-fieldset">
			<h2><?php esc_html_e( 'Tutor LMS learning data', 'tutor-user-cleanup' ); ?></h2>

			<?php if ( ! $tutor_active ) : ?>
				<p class="description">
					<?php esc_html_e( 'Tutor LMS is not active, so there is no learning data to remove.', 'tutor-user-cleanup' ); ?>
				</p>
			<?php else : ?>
				<?php if ( ! $version_ok ) : ?>
					<p class="description tutor-user-cleanup-warning">
						<strong><?php esc_html_e( 'Tutor LMS version notice:', 'tutor-user-cleanup' ); ?></strong>
						<?php
						printf(
							/* translators: 1: detected version, 2: tested-up-to version. */
							esc_html__( 'Detected version %1$s is outside the tested range (up to %2$s). The database structure may differ.', 'tutor-user-cleanup' ),
							esc_html( $repo->tutor_version() ? $repo->tutor_version() : __( 'unknown', 'tutor-user-cleanup' ) ),
							esc_html( TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION )
						);
						echo ' ';
						if ( $override_on ) {
							esc_html_e( 'The version override is ON, so cleanup will still run — proceed only if you have verified compatibility.', 'tutor-user-cleanup' );
						} else {
							esc_html_e( 'Live cleanup will be SKIPPED to protect your data. You can still preview below, or enable the override under Tools → Tutor User Cleanup.', 'tutor-user-cleanup' );
						}
						?>
					</p>
				<?php endif; ?>
				<p>
					<label>
						<input type="checkbox" name="tutor_user_cleanup" id="tutor_user_cleanup" value="1" <?php checked( $default_checked ); ?> />
						<strong><?php esc_html_e( 'Also wipe this user\'s Tutor LMS learning data', 'tutor-user-cleanup' ); ?></strong>
					</label>
				</p>
				<p class="description">
					<?php
					esc_html_e(
						'This removes course enrollments, course & lesson progress, completion status, and quiz attempts & answers — so the same email can re-register as a fresh learner.',
						'tutor-user-cleanup'
					);
					?>
				</p>
				<p class="description tutor-user-cleanup-preserve">
					<?php
					esc_html_e(
						'Orders, payments, earnings, reviews, Q&A and instructor-authored courses are NOT deleted.',
						'tutor-user-cleanup'
					);
					?>
				</p>
				<p class="description tutor-user-cleanup-warning">
					<strong><?php esc_html_e( 'Warning:', 'tutor-user-cleanup' ); ?></strong>
					<?php esc_html_e( 'Removed learning data cannot be recovered.', 'tutor-user-cleanup' ); ?>
				</p>

				<?php if ( $has_instructor ) : ?>
					<p class="description tutor-user-cleanup-warning">
						<?php
						esc_html_e(
							'Note: at least one selected account is an instructor. Their authored courses, earnings and payouts will be preserved; only their own learner records are affected.',
							'tutor-user-cleanup'
						);
						?>
					</p>
				<?php endif; ?>

				<p>
					<button type="button" class="button" id="tutor_user_cleanup_preview_btn"
						data-users="<?php echo esc_attr( implode( ',', $userids ) ); ?>">
						<?php esc_html_e( 'Preview what will be removed', 'tutor-user-cleanup' ); ?>
					</button>
					<span class="spinner" id="tutor_user_cleanup_spinner"></span>
				</p>
				<div id="tutor_user_cleanup_preview" class="tutor-user-cleanup-preview" aria-live="polite"></div>

				<?php wp_nonce_field( self::PREVIEW_NONCE, 'tutor_user_cleanup_preview_nonce' ); ?>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Enqueue assets on relevant screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		$is_users_screen = in_array( $hook, array( 'users.php', 'users-network.php' ), true );
		$is_settings     = isset( $_GET['page'] ) && 'tutor-user-cleanup' === sanitize_text_field( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_network      = function_exists( 'is_network_admin' ) && is_network_admin();

		if ( ! $is_users_screen && ! $is_settings ) {
			return;
		}

		wp_enqueue_style(
			'tutor-user-cleanup-admin',
			TUTOR_USER_CLEANUP_URL . 'assets/admin.css',
			array(),
			TUTOR_USER_CLEANUP_VERSION
		);

		wp_enqueue_script(
			'tutor-user-cleanup-admin',
			TUTOR_USER_CLEANUP_URL . 'assets/admin.js',
			array( 'jquery' ),
			TUTOR_USER_CLEANUP_VERSION,
			true
		);

		wp_localize_script(
			'tutor-user-cleanup-admin',
			'TutorUserCleanup',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'previewNonce'  => wp_create_nonce( self::PREVIEW_NONCE ),
				'isNetwork'     => $is_network ? 1 : 0,
				'confirmText'   => __( 'You ticked "wipe Tutor LMS learning data". This cannot be undone. Continue?', 'tutor-user-cleanup' ),
				'i18n'          => array(
					'enrollments'        => __( 'Enrollments', 'tutor-user-cleanup' ),
					'courses_reset'      => __( 'Courses with progress', 'tutor-user-cleanup' ),
					'course_completions' => __( 'Course completions', 'tutor-user-cleanup' ),
					'lesson_completions' => __( 'Lesson completions', 'tutor-user-cleanup' ),
					'quiz_attempts'      => __( 'Quiz attempts', 'tutor-user-cleanup' ),
					'gradebook_results'  => __( 'Gradebook results', 'tutor-user-cleanup' ),
					'nothing'            => __( 'No learning data found for the selected user(s).', 'tutor-user-cleanup' ),
					'error'              => __( 'Could not load preview.', 'tutor-user-cleanup' ),
					'previewFor'         => __( 'Learning data to be removed:', 'tutor-user-cleanup' ),
					'networkLabel'       => __( 'Also wipe Tutor LMS learning data (enrollments, progress, quiz attempts). Orders and financial data are preserved.', 'tutor-user-cleanup' ),
				),
			)
		);
	}

	/**
	 * AJAX: return dry-run counts for the given user IDs.
	 *
	 * @return void
	 */
	public function ajax_preview() {
		check_ajax_referer( self::PREVIEW_NONCE, 'nonce' );

		if ( ! current_user_can( 'delete_users' ) && ! current_user_can( 'manage_network_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'tutor-user-cleanup' ) ), 403 );
		}

		$raw      = isset( $_POST['users'] ) ? sanitize_text_field( wp_unslash( $_POST['users'] ) ) : '';
		$user_ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

		if ( empty( $user_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No users provided.', 'tutor-user-cleanup' ) ), 400 );
		}

		$totals = array();
		foreach ( $user_ids as $uid ) {
			$summary = $this->service->reset_learner( $uid, true ); // dry-run.
			if ( is_wp_error( $summary ) ) {
				continue;
			}
			foreach ( $summary['counts'] as $key => $value ) {
				$totals[ $key ] = ( isset( $totals[ $key ] ) ? $totals[ $key ] : 0 ) + (int) $value;
			}
		}

		wp_send_json_success( array( 'counts' => $totals ) );
	}

	/*
	|----------------------------------------------------------------------
	| Settings + log page
	|----------------------------------------------------------------------
	*/

	/**
	 * Register the Tools → Tutor User Cleanup page.
	 *
	 * @return void
	 */
	public function register_settings_page() {
		add_submenu_page(
			'tools.php',
			__( 'Tutor User Cleanup', 'tutor-user-cleanup' ),
			__( 'Tutor User Cleanup', 'tutor-user-cleanup' ),
			'manage_options',
			'tutor-user-cleanup',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Add a Settings link on the plugins list.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function plugin_action_links( $links ) {
		$url  = admin_url( 'tools.php?page=tutor-user-cleanup' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'tutor-user-cleanup' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Handle settings form submission.
	 *
	 * @return void
	 */
	public function maybe_save_settings() {
		if ( ! isset( $_POST['tutor_user_cleanup_settings_submit'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'tutor_user_cleanup_settings' );

		$input = isset( $_POST['tuc'] ) && is_array( $_POST['tuc'] )
			? wp_unslash( $_POST['tuc'] ) // phpcs:ignore WordPress.Security.ValidationSanitization.MissingUnslash -- sanitized in Settings::save().
			: array();

		Tutor_User_Cleanup_Settings::save( $input );

		add_settings_error( 'tutor_user_cleanup', 'saved', __( 'Settings saved.', 'tutor-user-cleanup' ), 'updated' );
	}

	/**
	 * Render the settings + log viewer page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s    = Tutor_User_Cleanup_Settings::all();
		$logs = Tutor_User_Cleanup_Logger::get_recent( 50 );

		$repo           = new Tutor_User_Cleanup_Tutor_Data_Repository();
		$detected       = $repo->tutor_version();
		$version_status = $repo->get_version_support_status();
		$status_labels  = array(
			'ok'      => __( 'Supported', 'tutor-user-cleanup' ),
			'too_old' => __( 'Older than supported', 'tutor-user-cleanup' ),
			'too_new' => __( 'Newer than tested', 'tutor-user-cleanup' ),
			'unknown' => __( 'Unknown', 'tutor-user-cleanup' ),
		);
		?>
		<div class="wrap tutor-user-cleanup-settings">
			<h1><?php esc_html_e( 'Tutor User Cleanup', 'tutor-user-cleanup' ); ?></h1>
			<?php settings_errors( 'tutor_user_cleanup' ); ?>

			<h2><?php esc_html_e( 'Tutor LMS compatibility', 'tutor-user-cleanup' ); ?></h2>
			<table class="widefat striped" style="max-width:560px">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Detected version', 'tutor-user-cleanup' ); ?></td>
						<td><strong><?php echo esc_html( $detected ? $detected : __( 'not detected', 'tutor-user-cleanup' ) ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Supported range', 'tutor-user-cleanup' ); ?></td>
						<td><?php echo esc_html( TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION . '  –  ' . TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Status', 'tutor-user-cleanup' ); ?></td>
						<td>
							<?php
							$label = isset( $status_labels[ $version_status ] ) ? $status_labels[ $version_status ] : $version_status;
							if ( 'ok' === $version_status ) {
								echo '<span style="color:#1d6b3b;font-weight:600">' . esc_html( $label ) . '</span>';
							} else {
								echo '<span style="color:#b32d2e;font-weight:600">' . esc_html( $label ) . '</span>';
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<?php if ( 'ok' !== $version_status ) : ?>
				<p class="description tutor-user-cleanup-warning">
					<?php esc_html_e( 'Live cleanup is blocked while the version is out of range, unless you enable the override below. Dry-run previews always work.', 'tutor-user-cleanup' ); ?>
				</p>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'tutor_user_cleanup_settings' ); ?>

				<h2><?php esc_html_e( 'Data types to wipe', 'tutor-user-cleanup' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$toggles = array(
						'wipe_enrollments'     => __( 'Course enrollments', 'tutor-user-cleanup' ),
						'wipe_course_progress' => __( 'Course & lesson progress / completion', 'tutor-user-cleanup' ),
						'wipe_quiz_attempts'   => __( 'Quiz attempts & answers', 'tutor-user-cleanup' ),
						'wipe_gradebook'       => __( 'Gradebook results (Tutor Pro, if present)', 'tutor-user-cleanup' ),
						'wipe_cache'           => __( 'Learner caches / transients', 'tutor-user-cleanup' ),
					);
					foreach ( $toggles as $key => $label ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( wp_strip_all_tags( $label ) ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="tuc[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> />
									<?php esc_html_e( 'Enabled', 'tutor-user-cleanup' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Behaviour', 'tutor-user-cleanup' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Logging', 'tutor-user-cleanup' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tuc[enable_logging]" value="1" <?php checked( ! empty( $s['enable_logging'] ) ); ?> />
								<?php esc_html_e( 'Record each cleanup operation', 'tutor-user-cleanup' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Version override', 'tutor-user-cleanup' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="tuc[allow_unsupported_version]" value="1" <?php checked( ! empty( $s['allow_unsupported_version'] ) ); ?> />
								<?php esc_html_e( 'Allow live cleanup even when the Tutor LMS version is outside the tested range', 'tutor-user-cleanup' ); ?>
							</label>
							<p class="description tutor-user-cleanup-warning">
								<?php esc_html_e( 'Leave OFF unless you have verified the Tutor LMS data structure still matches. When OFF, cleanup on an untested version is skipped (dry-run still works).', 'tutor-user-cleanup' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Log retention (days)', 'tutor-user-cleanup' ); ?></th>
						<td>
							<input type="number" min="0" name="tuc[log_retention_days]" value="<?php echo esc_attr( (int) $s['log_retention_days'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( '0 = keep forever.', 'tutor-user-cleanup' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="description">
					<?php esc_html_e( 'Financial and instructor data (orders, earnings, payouts, reviews, Q&A, authored courses) are always preserved and cannot be wiped by this plugin.', 'tutor-user-cleanup' ); ?>
				</p>

				<?php submit_button( __( 'Save settings', 'tutor-user-cleanup' ), 'primary', 'tutor_user_cleanup_settings_submit' ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Recent activity', 'tutor-user-cleanup' ); ?></h2>
			<?php if ( empty( $logs ) ) : ?>
				<p><?php esc_html_e( 'No cleanup operations logged yet.', 'tutor-user-cleanup' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'tutor-user-cleanup' ); ?></th>
							<th><?php esc_html_e( 'User ID', 'tutor-user-cleanup' ); ?></th>
							<th><?php esc_html_e( 'By', 'tutor-user-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Context', 'tutor-user-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Mode', 'tutor-user-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Result', 'tutor-user-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Counts', 'tutor-user-cleanup' ); ?></th>
							<th><?php esc_html_e( 'Message', 'tutor-user-cleanup' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td><?php echo esc_html( $row->target_user_id ); ?></td>
								<td><?php echo esc_html( $row->actor_user_id ); ?></td>
								<td><?php echo esc_html( $row->context ); ?></td>
								<td><?php echo $row->dry_run ? esc_html__( 'dry-run', 'tutor-user-cleanup' ) : esc_html__( 'live', 'tutor-user-cleanup' ); ?></td>
								<td><?php echo esc_html( $row->result ); ?></td>
								<td><code><?php echo esc_html( $this->format_counts( $row->counts ) ); ?></code></td>
								<td><?php echo esc_html( $row->message ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Format a JSON counts blob into a compact "key:value" string.
	 *
	 * @param string $json JSON string.
	 * @return string
	 */
	private function format_counts( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return '—';
		}

		if ( isset( $data['operations'] ) && is_array( $data['operations'] ) ) {
			$parts = array();
			foreach ( $data['operations'] as $key => $operation ) {
				if ( ! is_array( $operation ) ) {
					continue;
				}
				$requested = isset( $operation['requested'] ) ? (int) $operation['requested'] : 0;
				$deleted   = isset( $operation['deleted'] ) ? (int) $operation['deleted'] : 0;
				$failed    = isset( $operation['failed'] ) ? (int) $operation['failed'] : 0;
				if ( $requested > 0 || $failed > 0 ) {
					$parts[] = sprintf( '%s:%d/%d%s', $key, $deleted, $requested, $failed ? ' failed:' . $failed : '' );
				}
			}
			return empty( $parts ) ? '—' : implode( ' ', $parts );
		}

		$parts = array();
		foreach ( $data as $key => $value ) {
			if ( (int) $value > 0 ) {
				$parts[] = $key . ':' . (int) $value;
			}
		}
		return empty( $parts ) ? '—' : implode( ' ', $parts );
	}
}
