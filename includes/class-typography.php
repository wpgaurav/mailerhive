<?php
/**
 * Typography for the MailerHive reading shell: which fonts the pages use, the reading
 * size, line height, width and weights, and any extra @font-face rules.
 *
 * "Site font" detects what the site already loads, in this order: the Functionalities
 * fonts module (its assigned body font, else its first font), then the theme.json
 * body font. Both outputs survive theme isolation, so the font keeps loading on
 * MailerHive pages without a second copy.
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

final class Typography {
	const SYSTEM = 'system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif';
	const SERIF  = 'Charter,"Bitstream Charter","Sitka Text",Cambria,Georgia,serif';
	const MONO   = 'ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace';

	/** Choices for the body and heading font selects. */
	public static function choices( bool $heading = false ): array {
		$site  = self::site_family();
		$out   = $heading ? array( 'body' => __( 'Same as body text', 'mailerhive' ) ) : array();
		$out  += array(
			/* translators: %s: detected font family, or "none detected" */
			'site'   => sprintf( __( 'Site font (%s)', 'mailerhive' ), $site ? $site['family'] : __( 'none detected, uses system', 'mailerhive' ) ),
			'system' => __( 'System sans-serif', 'mailerhive' ),
			'serif'  => __( 'Book serif (Charter, Georgia)', 'mailerhive' ),
			'custom' => __( 'Custom font stack', 'mailerhive' ),
		);
		return $out;
	}

	/**
	 * The site's own text font: ['family' => ..., 'source' => ..., 'italic' => bool] or null.
	 */
	public static function site_family(): ?array {
		static $cache = false;
		if ( false !== $cache ) {
			return $cache;
		}
		$cache = null;

		$fx = get_option( 'functionalities_fonts' );
		if ( is_array( $fx ) && ! empty( $fx['items'] ) && is_array( $fx['items'] ) && self::functionalities_on( $fx ) ) {
			$family = '';
			if ( ! empty( $fx['assign_enabled'] ) && ! empty( $fx['body_font'] ) ) {
				$family = (string) $fx['body_font'];
			} else {
				foreach ( $fx['items'] as $item ) {
					if ( ! empty( $item['family'] ) ) {
						$family = (string) $item['family'];
						break;
					}
				}
			}
			if ( '' !== $family ) {
				$italic = false;
				foreach ( $fx['items'] as $item ) {
					if ( strcasecmp( (string) ( $item['family'] ?? '' ), $family ) === 0 && 'italic' === ( $item['style'] ?? '' ) ) {
						$italic = true;
					}
				}
				$cache = array(
					'family' => self::clean_family( $family ),
					'source' => 'Functionalities',
					'italic' => $italic,
				);
				return $cache;
			}
		}

		if ( function_exists( 'wp_get_global_styles' ) && function_exists( 'wp_get_global_settings' ) ) {
			$ref = (string) ( wp_get_global_styles( array( 'typography', 'fontFamily' ) ) ?: '' );
			if ( preg_match( '/--wp--preset--font-family--([a-z0-9-]+)/', $ref, $m ) || preg_match( '/var:preset\|font-family\|([a-z0-9-]+)/', $ref, $m ) ) {
				$sets = (array) wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
				foreach ( $sets as $origin ) {
					foreach ( (array) $origin as $preset ) {
						if ( ( $preset['slug'] ?? '' ) !== $m[1] || empty( $preset['fontFamily'] ) ) {
							continue;
						}
						$italic = false;
						foreach ( (array) ( $preset['fontFace'] ?? array() ) as $face ) {
							$italic = $italic || 'italic' === ( $face['fontStyle'] ?? '' );
						}
						$first = trim( explode( ',', (string) $preset['fontFamily'] )[0] );
						$cache = array(
							'family' => self::clean_family( $first ),
							'source' => 'theme.json',
							'italic' => $italic,
						);
						return $cache;
					}
				}
			}
		}
		return $cache;
	}

	private static function functionalities_on( array $opts ): bool {
		if ( class_exists( '\Functionalities\Core\Module_Registry' ) && method_exists( '\Functionalities\Core\Module_Registry', 'is_enabled' ) ) {
			return (bool) \Functionalities\Core\Module_Registry::is_enabled( 'fonts' );
		}
		return ! empty( $opts['enabled'] );
	}

	private static function clean_family( string $family ): string {
		$first = explode( ',', $family )[0];
		return trim( (string) preg_replace( '/[^A-Za-z0-9 _-]/', '', $first ) );
	}

	/** A CSS font-family value for one choice. */
	private static function stack( string $choice, string $custom ): string {
		switch ( $choice ) {
			case 'system':
				return self::SYSTEM;
			case 'serif':
				return self::SERIF;
			case 'custom':
				$custom = self::sanitize_stack( $custom );
				return '' !== $custom ? $custom . ',' . self::SYSTEM : self::SYSTEM;
			default:
				$site = self::site_family();
				return $site ? '"' . $site['family'] . '",' . self::SYSTEM : self::SYSTEM;
		}
	}

	/** Keep a font-family list to names, quotes, commas and spaces. */
	public static function sanitize_stack( string $stack ): string {
		$stack = preg_replace( '/[^A-Za-z0-9 ,"\'_-]/', '', $stack );
		return trim( preg_replace( '/\s*,\s*/', ',', (string) $stack ), " ,\t\n" );
	}

	public static function body_stack(): string {
		return self::stack( (string) Settings::get( 'font_body' ), (string) Settings::get( 'font_body_custom' ) );
	}

	public static function heading_stack(): string {
		$choice = (string) Settings::get( 'font_heading' );
		if ( 'body' === $choice || '' === $choice ) {
			return 'var(--mh-font)';
		}
		return self::stack( $choice, (string) Settings::get( 'font_heading_custom' ) );
	}

	/**
	 * Font stack for the email version: the reading font first, then system fonts.
	 * Most inboxes ignore web fonts, so the system fallback does the real work.
	 */
	public static function email_stack(): string {
		$first = trim( explode( ',', self::body_stack() )[0], " \"'" );
		$sys   = "-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
		if ( '' === $first || in_array( $first, array( 'system-ui', 'Charter' ), true ) ) {
			return 'Charter' === $first ? "Charter,Georgia,'Times New Roman',serif" : $sys;
		}
		return "'" . esc_attr( $first ) . "'," . $sys;
	}

	/** True when deks, captions and quotes should use italics. */
	public static function italic_accents(): bool {
		$mode = (string) Settings::get( 'italic_accents' );
		if ( 'on' === $mode ) {
			return true;
		}
		if ( 'off' === $mode ) {
			return false;
		}
		// Auto: only when the body font has a real italic. System and serif stacks do.
		$choice = (string) Settings::get( 'font_body' );
		if ( in_array( $choice, array( 'system', 'serif' ), true ) ) {
			return true;
		}
		if ( 'custom' === $choice ) {
			foreach ( self::faces() as $face ) {
				if ( 'italic' === $face['style'] ) {
					return true;
				}
			}
			return false;
		}
		$site = self::site_family();
		return $site ? (bool) $site['italic'] : true;
	}

	/**
	 * Extra @font-face rules, one per line: "Family | https://…/file.woff2 | 400 or 300 900 | normal or italic".
	 *
	 * @return array<int,array{family:string,url:string,weight:string,style:string}>
	 */
	public static function faces(): array {
		$out = array();
		foreach ( Settings::lines( 'font_faces' ) as $line ) {
			$parts  = array_map( 'trim', explode( '|', $line ) );
			$family = self::clean_family( (string) ( $parts[0] ?? '' ) );
			$url    = esc_url_raw( (string) ( $parts[1] ?? '' ), array( 'https', 'http' ) );
			if ( '' === $family || '' === $url ) {
				continue;
			}
			$weight = preg_match( '/^\d{3}(\s+\d{3})?$/', (string) ( $parts[2] ?? '' ) ) ? (string) $parts[2] : '400';
			$style  = 'italic' === strtolower( (string) ( $parts[3] ?? '' ) ) ? 'italic' : 'normal';
			$out[]  = compact( 'family', 'url', 'weight', 'style' );
		}
		return $out;
	}

	private static function format( string $url ): string {
		$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$map = array( 'woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype', 'otf' => 'opentype' );
		return $map[ $ext ] ?? 'woff2';
	}

	/** Preload the upright custom faces before the stylesheet. */
	public static function preload(): void {
		foreach ( self::faces() as $face ) {
			if ( 'normal' === $face['style'] ) {
				printf( '<link rel="preload" href="%s" as="font" type="font/%s" crossorigin>' . "\n", esc_url( $face['url'] ), esc_attr( 'truetype' === self::format( $face['url'] ) ? 'ttf' : self::format( $face['url'] ) ) );
			}
		}
	}

	/** Custom properties and @font-face rules appended to the shell stylesheet. */
	public static function css(): string {
		$css = '';
		foreach ( self::faces() as $face ) {
			$css .= sprintf(
				'@font-face{font-family:"%s";font-style:%s;font-weight:%s;font-display:swap;src:url(%s) format("%s")}',
				$face['family'],
				$face['style'],
				$face['weight'],
				esc_url( $face['url'] ),
				self::format( $face['url'] )
			);
		}
		$size    = min( 22, max( 14, (int) Settings::get( 'text_size' ) ?: 17 ) );
		$lh      = min( 2.1, max( 1.3, (float) Settings::get( 'line_height' ) ?: 1.75 ) );
		$measure = min( 960, max( 520, (int) Settings::get( 'measure' ) ?: 736 ) );
		$head_w  = min( 900, max( 400, (int) Settings::get( 'heading_weight' ) ?: 800 ) );
		$body_w  = min( 500, max( 300, (int) Settings::get( 'body_weight' ) ?: 400 ) );
		$mono    = self::sanitize_stack( (string) Settings::get( 'font_mono' ) ) ?: self::MONO;

		$vars = array(
			'--mh-font'       => self::body_stack(),
			'--mh-font-head'  => self::heading_stack(),
			'--mh-font-mono'  => $mono,
			'--mh-prose-size' => ( $size / 16 ) . 'rem',
			'--mh-prose-lh'   => (string) round( $lh, 2 ),
			'--mh-measure'    => ( $measure / 16 ) . 'rem',
			'--mh-w-head'     => (string) $head_w,
			'--mh-w-body'     => (string) $body_w,
			'--mh-w-strong'   => (string) min( 900, $body_w + 200 ),
		);
		$decl = '';
		foreach ( $vars as $k => $v ) {
			$decl .= $k . ':' . $v . ';';
		}
		return $css . ':root{' . $decl . '}';
	}
}
