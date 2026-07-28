<?php
/**
 * Uninstall handler — removes the plugin's own options and log table.
 *
 * Learner/financial data is never touched here; we only remove what THIS
 * plugin created.
 *
 * @package TutorUserCleanup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove plugin data for a single site.
 *
 * @return void
 */
function tutor_user_cleanup_uninstall_site() {
	global $wpdb;

	delete_option( 'tutor_user_cleanup_settings' );
	delete_option( 'tutor_user_cleanup_version' );

	$table = $wpdb->prefix . 'tutor_user_cleanup_log';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	wp_clear_scheduled_hook( 'tutor_user_cleanup_daily' );
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		tutor_user_cleanup_uninstall_site();
		restore_current_blog();
	}
} else {
	tutor_user_cleanup_uninstall_site();
}
