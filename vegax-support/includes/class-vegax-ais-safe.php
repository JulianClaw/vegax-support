<?php
/**
 * One place for input sanitising and output escaping.
 *
 * Every value that crosses the plugin boundary is either read from $_POST (input) or
 * printed into HTML (output). Doing that ad-hoc meant the same "unslash + sanitize" and
 * "cast + esc_*" dance was copy-pasted in a dozen spots, and one missed cast is a bug.
 * These helpers centralise both directions so a review only has to look here.
 *
 * Input helpers mirror EXACTLY the WordPress sanitisers used before, so behaviour for the
 * merchant is unchanged; they only remove the repetition.
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared sanitiser / escaper.
 */
class Vegax_AIS_Safe {

	/* ---------------------------------------------------------------------
	 * OUTPUT — escaping values that are printed to the admin or the shopper.
	 * ------------------------------------------------------------------- */

	/**
	 * Escape for an HTML text node.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function text( $value ) {
		return esc_html( (string) $value );
	}

	/**
	 * Escape for an HTML attribute value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function attr( $value ) {
		return esc_attr( (string) $value );
	}

	/**
	 * Escape for a URL attribute.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function url( $value ) {
		return esc_url( (string) $value );
	}

	/**
	 * Escape for a <textarea> body.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function textarea( $value ) {
		return esc_textarea( (string) $value );
	}

	/* ---------------------------------------------------------------------
	 * INPUT — reading and sanitising a $_POST field.
	 *
	 * Every helper below reads $_POST. They are ONLY ever called from a handler that has
	 * already passed check_admin_referer(); centralising the $_POST access here is the
	 * whole point, so the "no nonce" sniff is disabled for this block.
	 * phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
	 * ------------------------------------------------------------------- */

	/**
	 * A single-line text field.
	 *
	 * @param string $key     Field name.
	 * @param string $default Value when the field is absent.
	 * @return string
	 */
	public static function post_text( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
	}

	/**
	 * A multi-line text field.
	 *
	 * @param string $key     Field name.
	 * @param string $default Value when the field is absent.
	 * @return string
	 */
	public static function post_textarea( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		return sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) );
	}

	/**
	 * A URL field.
	 *
	 * @param string $key     Field name.
	 * @param string $default Value when the field is absent.
	 * @return string
	 */
	public static function post_url( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		return esc_url_raw( (string) wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * A #rrggbb colour field (falls back to the default when invalid).
	 *
	 * @param string $key     Field name.
	 * @param string $default Value when the field is absent or not a valid colour.
	 * @return string
	 */
	public static function post_color( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		$color = sanitize_hex_color( (string) wp_unslash( $_POST[ $key ] ) );
		return $color ? $color : $default;
	}

	/**
	 * An integer field, clamped to an optional range.
	 *
	 * @param string   $key     Field name.
	 * @param int      $default Value when the field is absent.
	 * @param int|null $min     Minimum (inclusive).
	 * @param int|null $max     Maximum (inclusive).
	 * @return int
	 */
	public static function post_int( $key, $default = 0, $min = null, $max = null ) {
		$value = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : absint( $default );
		if ( null !== $min && $value < $min ) {
			$value = (int) $min;
		}
		if ( null !== $max && $value > $max ) {
			$value = (int) $max;
		}
		return $value;
	}

	/**
	 * A checkbox: 1 when ticked, 0 otherwise.
	 *
	 * @param string $key Field name.
	 * @return int
	 */
	public static function post_bool( $key ) {
		return empty( $_POST[ $key ] ) ? 0 : 1;
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
}
