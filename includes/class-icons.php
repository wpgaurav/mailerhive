<?php
/**
 * Inline SVG icons (Tabler Icons, MIT; see assets/icons/LICENSE).
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

final class Icons {
	private static $cache = array();

	public static function get( string $name, string $class = '' ): string {
		$name = sanitize_key( $name );
		if ( ! isset( self::$cache[ $name ] ) ) {
			$file                 = MAILERHIVE_DIR . 'assets/icons/' . $name . '.svg';
			self::$cache[ $name ] = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$svg = self::$cache[ $name ];
		if ( '' === $svg ) {
			return '';
		}
		$svg = preg_replace( '/<!--.*?-->/s', '', $svg );
		$svg = preg_replace( '/\s(width|height|class)="[^"]*"/', '', $svg );
		$svg = preg_replace( '/<path stroke="none" d="M0 0h24v24H0z" fill="none"\s*\/>/', '', $svg );
		return trim( preg_replace( '/<svg\b/', '<svg class="mh-icon' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '" aria-hidden="true" focusable="false"', $svg, 1 ) );
	}
}
