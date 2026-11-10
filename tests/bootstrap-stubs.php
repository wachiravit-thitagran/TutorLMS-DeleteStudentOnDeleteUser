<?php
/**
 * Minimal WordPress stubs so the plugin's pure logic can be unit-tested with
 * plain PHP (no WordPress install, no database).
 *
 * Only the functions/classes actually exercised by the tests are stubbed.
 *
 * @package TutorUserCleanup\Tests
 */

// The plugin files guard on ABSPATH; satisfy it.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION' ) ) {
	define( 'TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION', '2.0.0' );
}
if ( ! defined( 'TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION' ) ) {
	define( 'TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION', '4.0.1' );
}

/**
 * Shared, resettable test state.
 */
$GLOBALS['_stub'] = array(
	'options'    => array(),
	'can'        => true,   // current_user_can result.
	'nonce_ok'   => true,   // wp_verify_nonce result.
	'last_nonce_action' => null,
	'valid_uids' => array(), // ids for which get_userdata returns a user.
	'current_user' => 1,
	'current_blog' => 1,
	'blog_stack'   => array(),
	'user_blogs'   => array(),
	'actions'    => array(), // tag => list of arg-arrays, recorded by do_action().
	'filters'    => array(), // tag => list of callables applied by apply_filters().
);

/* ------------------------------------------------------------------ */
/* Core helpers                                                        */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) {
		return abs( (int) $n );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return is_string( $s ) ? trim( $s ) : $s;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) {
		return $v;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Record fired actions so tests can assert the plugin's extension hooks
	 * fire with the right arguments.
	 */
	function do_action( $tag = '', ...$args ) {
		if ( ! isset( $GLOBALS['_stub']['actions'][ $tag ] ) ) {
			$GLOBALS['_stub']['actions'][ $tag ] = array();
		}
		$GLOBALS['_stub']['actions'][ $tag ][] = $args;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Register a filter callback (accepted-args/priority are ignored — the
	 * plugin only ever uses simple single-value filters).
	 */
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['_stub']['filters'][ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Run any registered callbacks over $value; returns $value untouched when
	 * nothing is hooked (matching the previous no-op stub's behaviour).
	 */
	function apply_filters( $tag, $value = null, ...$args ) {
		if ( empty( $GLOBALS['_stub']['filters'][ $tag ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['_stub']['filters'][ $tag ] as $cb ) {
			$value = call_user_func_array( $cb, array_merge( array( $value ), $args ) );
		}
		return $value;
	}
}

if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}

/**
 * Arg-arrays recorded for a fired action tag (empty when it never fired).
 *
 * @param string $tag Action tag.
 * @return array<int,array<int,mixed>>
 */
function tuc_actions_fired( $tag ) {
	return isset( $GLOBALS['_stub']['actions'][ $tag ] ) ? $GLOBALS['_stub']['actions'][ $tag ] : array();
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = false ) {
		unset( $gmt );
		if ( 'timestamp' === $type || 'U' === $type ) {
			return time();
		}
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return (int) $GLOBALS['_stub']['current_blog'];
	}
}

if ( ! function_exists( 'get_blogs_of_user' ) ) {
	function get_blogs_of_user( $user_id ) {
		unset( $user_id );
		return array_map(
			function ( $blog_id ) {
				return (object) array( 'userblog_id' => (int) $blog_id );
			},
			$GLOBALS['_stub']['user_blogs']
		);
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		$GLOBALS['_stub']['blog_stack'][]   = $GLOBALS['_stub']['current_blog'];
		$GLOBALS['_stub']['current_blog']   = (int) $blog_id;
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		if ( empty( $GLOBALS['_stub']['blog_stack'] ) ) {
			return false;
		}
		$GLOBALS['_stub']['current_blog'] = (int) array_pop( $GLOBALS['_stub']['blog_stack'] );
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) $GLOBALS['_stub']['current_user'];
	}
}

/* ------------------------------------------------------------------ */
/* Options                                                             */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['_stub']['options'] )
			? $GLOBALS['_stub']['options'][ $key ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['_stub']['options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['_stub']['options'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key ) {
		return true;
	}
}

/* ------------------------------------------------------------------ */
/* Capability / nonce / users                                         */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return (bool) $GLOBALS['_stub']['can'];
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		$GLOBALS['_stub']['last_nonce_action'] = $action;
		return $GLOBALS['_stub']['nonce_ok'] ? 1 : false;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return in_array( (int) $user_id, $GLOBALS['_stub']['valid_uids'], true )
			? (object) array( 'ID' => (int) $user_id, 'user_login' => 'user' . $user_id )
			: false;
	}
}

/* ------------------------------------------------------------------ */
/* WP_Error                                                            */
/* ------------------------------------------------------------------ */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/**
 * Reset shared test state between tests.
 *
 * @return void
 */
function tuc_reset_stub_state() {
	$GLOBALS['_stub'] = array(
		'options'      => array(),
		'can'          => true,
			'nonce_ok'     => true,
			'last_nonce_action' => null,
			'valid_uids'   => array(),
			'current_user' => 1,
			'current_blog' => 1,
			'blog_stack'   => array(),
			'user_blogs'   => array(),
			'actions'      => array(),
		'filters'      => array(),
	);
	$_REQUEST = array();
	$_POST    = array();
	$_GET     = array();
}

function is_multisite() {
	return ! empty( $GLOBALS['_stub']['multisite'] );
}
