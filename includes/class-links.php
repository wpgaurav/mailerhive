<?php
/**
 * Every external link inside an issue gets rel="nofollow noopener". Internal hosts
 * (the site and its subdomains, plus any configured extras) are left alone.
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

final class Links {
	public static function init(): void {
		add_filter( 'the_content', array( __CLASS__, 'filter' ), 99 );
	}

	public static function filter( $content ) {
		if ( ! is_string( $content ) || '' === $content || is_admin() || get_post_type() !== Issues::TYPE ) {
			return $content;
		}
		return self::nofollow( $content );
	}

	public static function is_external( string $href ): bool {
		if ( ! preg_match( '#^(https?:)?//#i', $href ) ) {
			return false; // relative, anchor, mailto:, tel:
		}
		$host = strtolower( (string) wp_parse_url( str_starts_with( $href, '//' ) ? 'https:' . $href : $href, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		foreach ( Settings::internal_hosts() as $h ) {
			if ( $host === $h || str_ends_with( $host, '.' . $h ) ) {
				return false;
			}
		}
		return true;
	}

	public static function nofollow( string $html ): string {
		$p = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag( 'a' ) ) {
			$href = (string) $p->get_attribute( 'href' );
			if ( '' === $href || ! self::is_external( $href ) ) {
				continue;
			}
			$rel = preg_split( '/\s+/', strtolower( trim( (string) $p->get_attribute( 'rel' ) ) ) ) ?: array();
			$rel = array_values( array_filter( array_unique( array_merge( $rel, array( 'nofollow', 'noopener' ) ) ) ) );
			$p->set_attribute( 'rel', implode( ' ', $rel ) );
		}
		return $p->get_updated_html();
	}
}
