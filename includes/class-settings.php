<?php
/**
 * MailerHive settings: one option, a small settings screen, and auto-detection of
 * values the site already holds (the Rybbit tag in theme footer scripts, the
 * MailerPress Newsletter list, the /newsletter/ page and the signup form).
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION = 'mailerhive_settings';

	public static function defaults(): array {
		return array(
			'brand_name'         => 'From the Workbench',
			'author_name'        => 'Gaurav Tiwari',
			'author_initials'    => 'GT',
			'tagline'            => 'Notes on WordPress, publishing, search, and independent products, sent when something real comes off my bench.',
			'landing_page_id'    => 0,
			'form_shortcode'     => '[cf_form slug="from-the-workbench"]',
			'mirror_list_ids'    => array(),
			'rybbit_src'         => '',
			'rybbit_site_id'     => '',
			'internal_hosts'     => array(),
			'privacy_url'        => '',
		);
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Fill blanks from what the site already has. Never overwrites a saved value.
	 */
	public static function seed(): void {
		$s = self::all();

		if ( ! $s['landing_page_id'] ) {
			$page = get_page_by_path( 'newsletter' );
			$s['landing_page_id'] = $page ? (int) $page->ID : 0;
		}

		if ( ! $s['rybbit_src'] || ! $s['rybbit_site_id'] ) {
			$md      = get_option( 'marketers_delight', array() );
			$scripts = (string) ( $md['settings']['footer_scripts'] ?? '' ) . (string) ( $md['settings']['header_scripts'] ?? '' );
			if ( preg_match( '/<script\b[^>]*\bsrc=["\']([^"\']*\/api\/script\.js)["\'][^>]*\bdata-site-id=["\']([A-Za-z0-9_-]+)["\']/i', $scripts, $m )
				|| preg_match( '/<script\b[^>]*\bdata-site-id=["\']([A-Za-z0-9_-]+)["\'][^>]*\bsrc=["\']([^"\']*\/api\/script\.js)["\']/i', $scripts, $m2 ) ) {
				if ( ! empty( $m ) ) {
					$s['rybbit_src']     = $m[1];
					$s['rybbit_site_id'] = $m[2];
				} else {
					$s['rybbit_src']     = $m2[2];
					$s['rybbit_site_id'] = $m2[1];
				}
			}
		}

		if ( ! $s['mirror_list_ids'] ) {
			global $wpdb;
			$table = $wpdb->prefix . 'mailerpress_lists';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT list_id FROM {$table} WHERE name = %s", 'Newsletter' ) );
				$s['mirror_list_ids'] = $id ? array( $id ) : array();
			}
		}

		if ( ! $s['privacy_url'] ) {
			$s['privacy_url'] = (string) get_privacy_policy_url();
		}

		update_option( self::OPTION, $s, false );
	}

	public static function internal_hosts(): array {
		$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ) );
		foreach ( (array) self::get( 'internal_hosts' ) as $h ) {
			$hosts[] = strtolower( trim( (string) $h ) );
		}
		$hosts = array_filter( array_unique( array_map( 'strtolower', $hosts ) ) );
		return array_values( $hosts );
	}

	public static function menu(): void {
		add_options_page( 'MailerHive', 'MailerHive', 'manage_options', 'mailerhive', array( __CLASS__, 'screen' ) );
	}

	public static function register(): void {
		register_setting(
			'mailerhive',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : array();
		$out = self::all();
		foreach ( array( 'brand_name', 'author_name', 'author_initials', 'tagline', 'form_shortcode', 'rybbit_site_id' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( wp_unslash( $in[ $k ] ) );
			}
		}
		foreach ( array( 'rybbit_src', 'privacy_url' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = esc_url_raw( wp_unslash( $in[ $k ] ) );
			}
		}
		if ( isset( $in['landing_page_id'] ) ) {
			$out['landing_page_id'] = absint( $in['landing_page_id'] );
		}
		if ( isset( $in['mirror_list_ids'] ) ) {
			$out['mirror_list_ids'] = array_values( array_filter( array_map( 'absint', explode( ',', (string) $in['mirror_list_ids'] ) ) ) );
		}
		if ( isset( $in['internal_hosts'] ) ) {
			$out['internal_hosts'] = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/[\s,]+/', (string) wp_unslash( $in['internal_hosts'] ) ) ) ) );
		}
		return $out;
	}

	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::all();
		$name  = self::OPTION;
		$field = static function ( string $key, string $label, string $value, string $help = '' ) use ( $name ): void {
			printf(
				'<tr><th scope="row"><label for="mh-%1$s">%2$s</label></th><td><input class="regular-text" id="mh-%1$s" name="%3$s[%1$s]" value="%4$s">%5$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( $value ),
				$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
			);
		};
		echo '<div class="wrap"><h1>MailerHive</h1><form method="post" action="options.php">';
		settings_fields( 'mailerhive' );
		echo '<table class="form-table" role="presentation">';
		$field( 'brand_name', 'Newsletter name', $s['brand_name'] );
		$field( 'author_name', 'Sender name', $s['author_name'] );
		$field( 'author_initials', 'Avatar initials', $s['author_initials'] );
		$field( 'tagline', 'Tagline', $s['tagline'] );
		$field( 'landing_page_id', 'Newsletter page ID', (string) $s['landing_page_id'], 'The signup page linked from every issue.' );
		$field( 'form_shortcode', 'Signup form shortcode', $s['form_shortcode'] );
		$field( 'mirror_list_ids', 'Mirror campaigns sent to list IDs', implode( ',', $s['mirror_list_ids'] ), 'Sent MailerPress newsletters to these lists are published as issue pages. Campaigns created from an issue are never mirrored twice.' );
		$field( 'rybbit_src', 'Rybbit script URL', $s['rybbit_src'] );
		$field( 'rybbit_site_id', 'Rybbit site ID', $s['rybbit_site_id'] );
		$field( 'internal_hosts', 'Extra internal hosts', implode( ', ', $s['internal_hosts'] ), 'Links to any other host get rel="nofollow" on issue pages.' );
		$field( 'privacy_url', 'Privacy policy URL', $s['privacy_url'] );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}
}
