<?php
/**
 * PHPUnit bootstrap for the Vegax Support plugin.
 *
 * The unit tests exercise the security-critical helpers (the OAuth `state` check, the
 * signed identity token, the REST signature verifier, settings/escaping helpers). They
 * run WITHOUT a WordPress install: the handful of WordPress functions and classes the
 * code touches are stubbed below with faithful, minimal behaviour.
 *
 * Plugin directory resolution:
 *   - CI / repo root :  ../vegax-support  (this file lives in tests/)
 *   - other layouts  :  set VEGAX_PLUGIN_DIR to the plugin folder.
 *
 * @package Vegax_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'VEGAX_AIS_VERSION' ) ) {
	define( 'VEGAX_AIS_VERSION', '1.0.8' );
}
if ( ! defined( 'VEGAX_AIS_OPTION' ) ) {
	define( 'VEGAX_AIS_OPTION', 'vegax_ais_settings' );
}
if ( ! defined( 'VEGAX_AIS_API_DEFAULT' ) ) {
	define( 'VEGAX_AIS_API_DEFAULT', 'https://office.vegaxclaw.com' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['vegax_test_options']       = array();
$GLOBALS['vegax_test_transients']    = array();
$GLOBALS['vegax_test_logged_in']     = false;
$GLOBALS['vegax_test_user_id']       = 7;
$GLOBALS['vegax_test_nocache_called'] = false;
$GLOBALS['vegax_test_headers']        = array();

/* -------------------------------------------------------------------------
 * Option / transient store (in memory).
 * ---------------------------------------------------------------------- */
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['vegax_test_options'] )
			? $GLOBALS['vegax_test_options'][ $name ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['vegax_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['vegax_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['vegax_test_transients'] )
			? $GLOBALS['vegax_test_transients'][ $key ]
			: false;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['vegax_test_transients'][ $key ] );
		return true;
	}
}

/* -------------------------------------------------------------------------
 * Hooks / i18n / escaping.
 * ---------------------------------------------------------------------- */
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $prio = 10, $args = 1 ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		$url = (string) $url;
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_hex_color' ) ) {
	function sanitize_hex_color( $color ) {
		return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', (string) $color ) ? $color : null;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) {
		return abs( (int) $n );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

/* -------------------------------------------------------------------------
 * HTTP + scheduler stubs (queue-driven, no network).
 * ---------------------------------------------------------------------- */
$GLOBALS['vegax_test_http'] = array();
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$q = $GLOBALS['vegax_test_http'];
		if ( empty( $q ) ) {
			return new WP_Error( 'vegax_test_no_http', 'no queued HTTP response' );
		}
		return array_shift( $GLOBALS['vegax_test_http'] );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return ( is_array( $r ) && isset( $r['response']['code'] ) ) ? (int) $r['response']['code'] : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return ( is_array( $r ) && isset( $r['body'] ) ) ? (string) $r['body'] : '';
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $ts, $recurrence, $hook ) {
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook ) {
		return true;
	}
}
if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $ts = 0 ) {
		return gmdate( $format, (int) $ts );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return untrailingslashit( $string ) . '/';
	}
}

/* -------------------------------------------------------------------------
 * Site / request environment.
 * ---------------------------------------------------------------------- */
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://shop.example.com' . $path;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		return false;
	}
}
if ( ! function_exists( 'is_feed' ) ) {
	function is_feed() {
		return false;
	}
}
if ( ! function_exists( 'is_robots' ) ) {
	function is_robots() {
		return false;
	}
}
if ( ! function_exists( 'is_preview' ) ) {
	function is_preview() {
		return false;
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return ! empty( $GLOBALS['vegax_test_logged_in'] );
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		$user     = new stdClass();
		$user->ID = (int) $GLOBALS['vegax_test_user_id'];
		return $user;
	}
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {
		$GLOBALS['vegax_test_nocache_called'] = true;
	}
}
if ( ! function_exists( 'headers_sent' ) ) {
	function headers_sent() {
		return false;
	}
}
if ( ! function_exists( 'header' ) ) {
	function header( $header, $replace = true ) {
		$GLOBALS['vegax_test_headers'][] = $header;
	}
}

/* -------------------------------------------------------------------------
 * WordPress classes used by the code under test.
 * ---------------------------------------------------------------------- */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();
		public function __construct( $code = '', $message = '', $data = null ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = array(
					'message' => $message,
					'data'    => $data,
				);
			}
		}
		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}
		public function get_error_message() {
			$codes = array_keys( $this->errors );
			return $codes ? $this->errors[ $codes[0] ][0]['message'] : '';
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();
		public function __construct( $params = array() ) {
			$this->params = is_array( $params ) ? $params : array();
		}
		public function get_param( $key ) {
			return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : null;
		}
		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private $status;
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function get_data() {
			return $this->data;
		}
		public function get_status() {
			return $this->status;
		}
	}
}

/* -------------------------------------------------------------------------
 * Load the classes under test.
 * ---------------------------------------------------------------------- */
$vegax_plugin_dir = getenv( 'VEGAX_PLUGIN_DIR' );
if ( ! $vegax_plugin_dir ) {
	$vegax_plugin_dir = dirname( __DIR__ ) . '/vegax-support';
}
$vegax_plugin_dir = rtrim( $vegax_plugin_dir, '/' );

foreach ( array(
	'class-vegax-ais-settings.php',
	'class-vegax-ais-tokens.php',
	'class-vegax-ais-safe.php',
	'class-vegax-ais-identity.php',
	'class-vegax-ais-rest.php',
	'class-vegax-ais-widget.php',
	'class-vegax-ais-admin.php',
) as $vegax_file ) {
	require_once $vegax_plugin_dir . '/includes/' . $vegax_file;
}

/**
 * Reset all in-memory state between tests.
 *
 * @param array $options Option overrides for the next test.
 * @return void
 */
function vegax_test_reset( array $options = array() ) {
	$GLOBALS['vegax_test_options']        = array( VEGAX_AIS_OPTION => $options );
	$GLOBALS['vegax_test_transients']     = array();
	$GLOBALS['vegax_test_logged_in']      = false;
	$GLOBALS['vegax_test_user_id']        = 7;
	$GLOBALS['vegax_test_nocache_called'] = false;
	$GLOBALS['vegax_test_headers']        = array();
	$GLOBALS['vegax_test_http']           = array();
}
