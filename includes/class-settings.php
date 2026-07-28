<?php
/**
 * Settings handling: which data types to wipe, logging and version safety.
 *
 * @package TutorUserCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Tutor_User_Cleanup_Settings
 *
 * Thin wrapper around a single option array so defaults stay in one place
 * and every consumer reads booleans through a typed accessor.
 */
class Tutor_User_Cleanup_Settings {

	const OPTION_KEY = 'tutor_user_cleanup_settings';

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Which learner data types are eligible for wiping.
			'wipe_enrollments'      => true,
			'wipe_course_progress'  => true, // course + lesson completion.
			'wipe_quiz_attempts'    => true, // attempts + answers.
			'wipe_gradebook'        => true, // Tutor Pro gradebook results (if present).
			'wipe_cache'            => true,

			// Behaviour.
				'enable_logging'           => true,
			'log_retention_days'       => 180,

			// Safety override: allow live cleanup even when the installed Tutor LMS
			// version is outside the tested range. Off by default — turn on only
			// after verifying the data model still matches.
			'allow_unsupported_version' => false,

			// Safety: never allow financial/instructor wiping from this plugin.
			// (Kept as an explicit, read-only reminder — not user-editable.)
			'preserve_financial'    => true,
		);
	}

	/**
	 * Get the full, merged settings array.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$merged = wp_parse_args( $saved, self::defaults() );

		// Hard-guard: financial preservation can never be turned off.
		$merged['preserve_financial'] = true;

		return $merged;
	}

	/**
	 * Get a single boolean/scalar setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if not set.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * True when the given data-type toggle is enabled.
	 *
	 * @param string $type One of enrollments|course_progress|quiz_attempts|gradebook|cache.
	 * @return bool
	 */
	public static function is_type_enabled( $type ) {
		return (bool) self::get( 'wipe_' . $type, false );
	}

	/**
	 * Persist a sanitized settings array (from the settings screen).
	 *
	 * @param array<string,mixed> $input Raw form input.
	 * @return array<string,mixed> Saved settings.
	 */
	public static function save( array $input ) {
		$defaults  = self::defaults();
		$sanitized = array();

		foreach ( $defaults as $key => $default ) {
			if ( 'log_retention_days' === $key ) {
				// Clamp to >= 0. Cast with (int) rather than absint() so a negative value
				// clamps to 0 ("keep forever") instead of flipping to its positive magnitude.
				$sanitized[ $key ] = isset( $input[ $key ] ) ? max( 0, (int) $input[ $key ] ) : $default;
				continue;
			}
			if ( 'preserve_financial' === $key ) {
				$sanitized[ $key ] = true; // immutable.
				continue;
			}
			$sanitized[ $key ] = ! empty( $input[ $key ] );
		}

		update_option( self::OPTION_KEY, $sanitized );

		return $sanitized;
	}

	/**
	 * Install defaults on activation (without overwriting an existing config).
	 *
	 * @return void
	 */
	public static function install_defaults() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			update_option( self::OPTION_KEY, self::defaults() );
		}
	}
}
