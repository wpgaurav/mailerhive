<?php
/**
 * MailerHive settings: one option, a tabbed screen under Newsletter → Settings, and
 * auto-detection of values the site already holds (Rybbit tag in theme footer scripts,
 * the MailerPress Newsletter list, the /newsletter/ page, the signup form).
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION      = 'mailerhive_settings';
	const SYNC_OPTION = 'mailerhive_last_sync';
	const FLUSH_FLAG  = 'mailerhive_flush_rewrites';

	/** Checkbox fields per tab, so a partial (one-tab) save can record "off". */
	const CHECKBOXES = array(
		'issues'      => array( 'show_subscribe', 'show_pager', 'nofollow', 'external_new_tab' ),
		'design'      => array( 'detach_theme', 'shell_mailerpress_pages', 'show_theme_toggle' ),
		'mailerpress' => array( 'auto_draft', 'mirror_enabled', 'redirect_web_view' ),
		'analytics'   => array( 'rybbit_enabled' ),
	);

	const TABS = array(
		'general'     => 'General',
		'issues'      => 'Issues',
		'design'      => 'Design',
		'mailerpress' => 'MailerPress',
		'analytics'   => 'Analytics',
		'tools'       => 'Tools & status',
	);

	public static function defaults(): array {
		return array(
			// General.
			'brand_name'              => 'From the Workbench',
			'author_name'             => 'Gaurav Tiwari',
			'author_initials'         => 'GT',
			'avatar_url'              => '',
			'avatar_id'               => 0,
			'header_style'            => 'avatar',
			'logo_id'                 => 0,
			'logo_dark_id'            => 0,
			'logo_width'              => 140,
			'byline'                  => 'by Gaurav Tiwari',
			'sender_line'             => '',
			'subscribe_label'         => 'Subscribe',
			'tagline'                 => 'Notes on WordPress, publishing, search, and independent products, sent when something real comes off my bench.',
			'landing_page_id'         => 0,
			'form_slug'               => 'from-the-workbench',
			'form_shortcode'          => '',
			// Issues.
			'issue_base'              => 'newsletter/issue',
			'archive_title'           => '',
			'archive_intro'           => '',
			'subscribe_heading'       => 'Get the next note from my workbench',
			'subscribe_facts'         => "Free\nNo fixed schedule\nUnsubscribe from any issue",
			'show_subscribe'          => true,
			'show_pager'              => true,
			'nofollow'                => true,
			'external_new_tab'        => false,
			'internal_hosts'          => array(),
			// Design.
			'detach_theme'            => true,
			'shell_mailerpress_pages' => true,
			'color_scheme'            => 'auto',
			'show_theme_toggle'       => true,
			'accent_light'            => '#C0392B',
			'accent_dark'             => '#E8836F',
			'header_links'            => '',
			'footer_note'             => 'Already subscribed? Every email ends with links to manage or cancel your subscription.',
			'footer_links'            => '',
			'privacy_url'             => '',
			'custom_css'              => '',
			// MailerPress.
			'auto_draft'              => false,
			'draft_list_ids'          => array(),
			'sender_name'             => '',
			'sender_email'            => '',
			'subject_template'        => '{title}',
			'email_footer'            => 'You get this because you subscribed to From the Workbench at gauravtiwari.org.',
			'mirror_enabled'          => true,
			'mirror_list_ids'         => array(),
			'mirror_status'           => 'publish',
			'redirect_web_view'       => true,
			// Analytics.
			'rybbit_enabled'          => true,
			'rybbit_src'              => '',
			'rybbit_site_id'          => '',
			'head_html'               => '',
		);
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_mailerhive_tool', array( __CLASS__, 'run_tool' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'plugin_action_links_' . plugin_basename( MAILERHIVE_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function on( string $key ): bool {
		return (bool) self::get( $key );
	}

	public static function url(): string {
		return admin_url( 'edit.php?post_type=' . Issues::TYPE . '&page=mailerhive' );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Settings</a>' );
		return $links;
	}

	/* ---------- derived values ---------- */

	public static function form_shortcode(): string {
		$custom = trim( (string) self::get( 'form_shortcode' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		$slug = sanitize_title( (string) self::get( 'form_slug' ) );
		return $slug ? '[cf_form slug="' . $slug . '"]' : '';
	}

	public static function issue_base(): string {
		$base = trim( (string) self::get( 'issue_base' ), '/' );
		$base = implode( '/', array_filter( array_map( 'sanitize_title', explode( '/', $base ) ) ) );
		return $base ?: 'newsletter/issue';
	}

	/** "Label | URL" lines to [label, url] pairs. */
	public static function link_lines( string $key ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( $key ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$out[] = array( $parts[0], $parts[1] );
			}
		}
		return $out;
	}

	public static function lines( string $key ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) self::get( $key ) ) ) ) );
	}

	public static function internal_hosts(): array {
		$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ) );
		foreach ( (array) self::get( 'internal_hosts' ) as $h ) {
			$hosts[] = strtolower( trim( (string) $h ) );
		}
		return array_values( array_filter( array_unique( array_map( 'strtolower', $hosts ) ) ) );
	}

	/**
	 * Fill blanks from what the site already has. Never overwrites a saved value.
	 */
	public static function seed(): void {
		$s = self::all();

		if ( ! $s['landing_page_id'] ) {
			$page                 = get_page_by_path( 'newsletter' );
			$s['landing_page_id'] = $page ? (int) $page->ID : 0;
		}

		if ( ! $s['rybbit_src'] || ! $s['rybbit_site_id'] ) {
			$md      = get_option( 'marketers_delight', array() );
			$scripts = (string) ( $md['settings']['footer_scripts'] ?? '' ) . (string) ( $md['settings']['header_scripts'] ?? '' );
			if ( preg_match( '/<script\b[^>]*>/i', $scripts ) ) {
				foreach ( array_filter( preg_split( '/(?=<script\b)/i', $scripts ) ) as $tag ) {
					if ( preg_match( '/\bsrc=["\']([^"\']*\/script\.js)["\']/i', $tag, $src ) && preg_match( '/\bdata-site-id=["\']([A-Za-z0-9_-]+)["\']/i', $tag, $id ) ) {
						$s['rybbit_src']     = $src[1];
						$s['rybbit_site_id'] = $id[1];
						break;
					}
				}
			}
		}

		if ( ! $s['mirror_list_ids'] ) {
			foreach ( self::mailerpress_lists() as $id => $name ) {
				if ( 'Newsletter' === $name ) {
					$s['mirror_list_ids'] = array( (int) $id );
				}
			}
		}

		if ( ! $s['privacy_url'] ) {
			$s['privacy_url'] = (string) get_privacy_policy_url();
		}

		if ( '' === trim( (string) $s['header_links'] ) ) {
			$s['header_links'] = 'All issues | /' . self::issue_base() . '/';
		}

		if ( '' === trim( (string) $s['footer_links'] ) ) {
			$lines = array( 'All issues | /' . self::issue_base() . '/', 'gauravtiwari.org | /' );
			if ( $s['privacy_url'] ) {
				$lines[] = 'Privacy | ' . $s['privacy_url'];
			}
			$s['footer_links'] = implode( "\n", $lines );
		}

		update_option( self::OPTION, $s, false );
	}

	public static function maybe_flush(): void {
		if ( get_option( self::FLUSH_FLAG ) ) {
			delete_option( self::FLUSH_FLAG );
			flush_rewrite_rules( false );
		}
	}

	/* ---------- data sources for the screen ---------- */

	public static function mailerpress_lists(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mailerpress_lists';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$out = array();
		foreach ( $wpdb->get_results( "SELECT list_id, name FROM {$table} ORDER BY name" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ (int) $row->list_id ] = (string) $row->name;
		}
		return $out;
	}

	public static function core_forms(): array {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'core-form', 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $f ) {
			$out[ $f->post_name ] = $f->post_title;
		}
		return $out;
	}

	/* ---------- registration and sanitizing ---------- */

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Issues::TYPE, 'MailerHive Settings', 'Settings', 'manage_options', 'mailerhive', array( __CLASS__, 'screen' ) );
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
		$in  = is_array( $in ) ? wp_unslash( $in ) : array();
		$out = self::all();
		$tab = sanitize_key( $in['_tab'] ?? '' );

		foreach ( array( 'brand_name', 'author_name', 'author_initials', 'archive_title', 'subscribe_heading', 'footer_note', 'rybbit_site_id', 'form_shortcode', 'byline', 'sender_line', 'subscribe_label', 'sender_name', 'subject_template', 'email_footer' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		foreach ( array( 'tagline', 'archive_intro', 'subscribe_facts', 'header_links', 'footer_links' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_textarea_field( $in[ $k ] );
			}
		}
		foreach ( array( 'avatar_url', 'rybbit_src', 'privacy_url' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = esc_url_raw( $in[ $k ] );
			}
		}
		if ( isset( $in['form_slug'] ) ) {
			$out['form_slug'] = sanitize_title( $in['form_slug'] );
		}
		if ( isset( $in['landing_page_id'] ) ) {
			$out['landing_page_id'] = absint( $in['landing_page_id'] );
		}
		foreach ( array( 'avatar_id', 'logo_id', 'logo_dark_id' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = absint( $in[ $k ] );
			}
		}
		if ( isset( $in['logo_width'] ) ) {
			$out['logo_width'] = min( 400, max( 40, absint( $in['logo_width'] ) ) );
		}
		if ( isset( $in['header_style'] ) ) {
			$out['header_style'] = in_array( $in['header_style'], array( 'avatar', 'logo', 'logo_text' ), true ) ? $in['header_style'] : 'avatar';
		}
		if ( isset( $in['issue_base'] ) ) {
			$old               = self::issue_base();
			$out['issue_base'] = $in['issue_base'];
			$out['issue_base'] = implode( '/', array_filter( array_map( 'sanitize_title', explode( '/', trim( (string) $in['issue_base'], '/' ) ) ) ) ) ?: 'newsletter/issue';
			if ( $out['issue_base'] !== $old ) {
				update_option( self::FLUSH_FLAG, 1, false );
			}
		}
		if ( isset( $in['internal_hosts'] ) ) {
			$out['internal_hosts'] = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/[\s,]+/', (string) $in['internal_hosts'] ) ) ) );
		}
		if ( isset( $in['color_scheme'] ) ) {
			$out['color_scheme'] = in_array( $in['color_scheme'], array( 'auto', 'light', 'dark' ), true ) ? $in['color_scheme'] : 'auto';
		}
		foreach ( array( 'accent_light', 'accent_dark' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_hex_color( $in[ $k ] ) ?: self::defaults()[ $k ];
			}
		}
		if ( isset( $in['custom_css'] ) ) {
			$out['custom_css'] = str_replace( '<', '', wp_strip_all_tags( (string) $in['custom_css'] ) );
		}
		if ( isset( $in['mirror_status'] ) ) {
			$out['mirror_status'] = 'draft' === $in['mirror_status'] ? 'draft' : 'publish';
		}
		if ( 'mailerpress' === $tab ) {
			$out['mirror_list_ids'] = array_values( array_filter( array_map( 'absint', (array) ( $in['mirror_list_ids'] ?? array() ) ) ) );
			$out['draft_list_ids']  = array_values( array_filter( array_map( 'absint', (array) ( $in['draft_list_ids'] ?? array() ) ) ) );
		}
		if ( isset( $in['sender_email'] ) ) {
			$out['sender_email'] = sanitize_email( $in['sender_email'] );
		}
		if ( isset( $in['head_html'] ) ) {
			$out['head_html'] = current_user_can( 'unfiltered_html' ) ? (string) $in['head_html'] : wp_kses_post( (string) $in['head_html'] );
		}
		foreach ( self::CHECKBOXES[ $tab ] ?? array() as $k ) {
			$out[ $k ] = ! empty( $in[ $k ] );
		}
		return $out;
	}

	/* ---------- tools ---------- */

	public static function run_tool(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'mailerhive_tool' );
		$tool = sanitize_key( $_REQUEST['tool'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
		$msg  = '';
		$back = 'tools';
		switch ( $tool ) {
			case 'sync':
				$r   = MailerPress_Bridge::sync();
				$msg = sprintf( 'Sync finished: %d campaign(s) linked, %d newsletter(s) published.', $r['linked'], $r['mirrored'] );
				break;
			case 'relink':
				$msg = sprintf( 'Relinked %d issue(s).', Importer::relink() );
				break;
			case 'import':
				$slugs = array_filter( array_map( 'sanitize_title', preg_split( '/[\s,]+/', (string) wp_unslash( $_POST['slugs'] ?? '' ) ) ) );
				$pub   = sanitize_key( wp_unslash( $_POST['publication'] ?? 'gauravtiwari' ) );
				$done  = array();
				foreach ( $slugs as $slug ) {
					$r      = Importer::substack( $slug, $pub );
					$done[] = $slug . ': ' . $r['message'];
				}
				Importer::relink();
				$msg = $done ? implode( ' · ', $done ) : 'No slugs given.';
				break;
			case 'flush':
				flush_rewrite_rules( false );
				$msg = 'Permalinks refreshed.';
				break;
			case 'seed':
				self::seed();
				$msg = 'Blank settings refilled from the site.';
				break;
			case 'enable_auto_draft':
			case 'disable_auto_draft':
				$all               = self::all();
				$all['auto_draft'] = 'enable_auto_draft' === $tool;
				update_option( self::OPTION, $all, false );
				$msg  = $all['auto_draft'] ? 'Automatic drafts are on. The next issue you publish becomes a MailerPress draft.' : 'Automatic drafts are off.';
				$back = 'mailerpress';
				break;
			case 'draft_latest':
				$latest = get_posts( array( 'post_type' => Issues::TYPE, 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
				$r      = $latest ? Email::create_draft( (int) $latest[0]->ID ) : array( 'message' => 'There is no published issue yet.', 'campaign_id' => 0 );
				$msg    = $r['message'];
				$back   = 'mailerpress';
				if ( ! empty( $r['campaign_id'] ) ) {
					set_transient( 'mailerhive_notice_' . get_current_user_id(), $msg, 60 );
					wp_safe_redirect( Email::edit_url( (int) $r['campaign_id'] ) );
					exit;
				}
				break;
		}
		set_transient( 'mailerhive_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( add_query_arg( 'tab', $back, self::url() ) );
		exit;
	}

	/* ---------- screen ---------- */

	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = sanitize_key( $_GET['tab'] ?? 'general' ); // phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( self::TABS[ $tab ] ) ? $tab : 'general';
		$s   = self::all();

		echo '<div class="wrap mailerhive-settings"><h1>MailerHive</h1>';
		$notice = get_transient( 'mailerhive_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'mailerhive_notice_' . get_current_user_id() );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		settings_errors( 'mailerhive' );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			printf( '<a class="nav-tab%s" href="%s">%s</a>', $key === $tab ? ' nav-tab-active' : '', esc_url( add_query_arg( 'tab', $key, self::url() ) ), esc_html( $label ) );
		}
		echo '</nav>';

		if ( 'tools' === $tab ) {
			self::tools_tab();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'mailerhive' );
		printf( '<input type="hidden" name="%s[_tab]" value="%s">', esc_attr( self::OPTION ), esc_attr( $tab ) );
		echo '<table class="form-table" role="presentation">';
		call_user_func( array( __CLASS__, 'tab_' . $tab ), $s );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	private static function name( string $key ): string {
		return self::OPTION . '[' . $key . ']';
	}

	private static function row( string $label, string $html, string $help = '', string $for = '' ): void {
		printf(
			'<tr><th scope="row">%s</th><td>%s%s</td></tr>',
			$for ? '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label ),
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : ''
		);
	}

	private static function text( array $s, string $key, string $label, string $help = '', string $class = 'regular-text', string $type = 'text' ): void {
		self::row( $label, sprintf( '<input type="%s" class="%s" id="mh-%s" name="%s" value="%s">', esc_attr( $type ), esc_attr( $class ), esc_attr( $key ), esc_attr( self::name( $key ) ), esc_attr( (string) $s[ $key ] ) ), $help, 'mh-' . $key );
	}

	private static function area( array $s, string $key, string $label, string $help = '', int $rows = 4, bool $code = false ): void {
		self::row( $label, sprintf( '<textarea class="large-text%s" rows="%d" id="mh-%s" name="%s">%s</textarea>', $code ? ' code' : '', $rows, esc_attr( $key ), esc_attr( self::name( $key ) ), esc_textarea( is_array( $s[ $key ] ) ? implode( "\n", $s[ $key ] ) : (string) $s[ $key ] ) ), $help, 'mh-' . $key );
	}

	private static function check( array $s, string $key, string $label, string $text, string $help = '' ): void {
		self::row( $label, sprintf( '<label><input type="checkbox" name="%s" value="1"%s> %s</label>', esc_attr( self::name( $key ) ), checked( ! empty( $s[ $key ] ), true, false ), esc_html( $text ) ), $help );
	}

	private static function select( array $s, string $key, string $label, array $options, string $help = '' ): void {
		$html = sprintf( '<select id="mh-%s" name="%s">', esc_attr( $key ), esc_attr( self::name( $key ) ) );
		foreach ( $options as $value => $text ) {
			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( (string) $value ), selected( (string) $s[ $key ], (string) $value, false ), esc_html( $text ) );
		}
		self::row( $label, $html . '</select>', $help, 'mh-' . $key );
	}

	private static function media( array $s, string $key, string $label, string $help = '' ): void {
		$id  = (int) $s[ $key ];
		$img = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		$html = sprintf(
			'<div class="mh-media" data-target="mh-%1$s"><input type="hidden" id="mh-%1$s" name="%2$s" value="%3$d"><img class="mh-media__preview" src="%4$s" alt=""%5$s> <button type="button" class="button mh-media__pick">Choose image</button> <button type="button" class="button-link-delete mh-media__clear"%5$s>Remove</button></div>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			$id,
			esc_url( (string) $img ),
			$img ? '' : ' hidden'
		);
		self::row( $label, $html, $help );
	}

	public static function admin_assets( string $hook ): void {
		if ( ! isset( $_GET['page'] ) || 'mailerhive' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_enqueue_media();
		wp_add_inline_style( 'wp-admin', '.mh-media__preview{display:block;max-width:200px;max-height:90px;margin:0 0 8px;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;padding:4px}.mh-media__preview[hidden],.mh-media__clear[hidden]{display:none}.mailerhive-settings h2.title{margin-top:2em}' );
		wp_add_inline_script(
			'media-editor',
			"jQuery(function($){\$(document).on('click','.mh-media__pick',function(e){e.preventDefault();var box=\$(this).closest('.mh-media');var frame=wp.media({title:'Choose image',library:{type:'image'},multiple:false});frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();box.find('input').val(a.id);var u=(a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url);box.find('.mh-media__preview').attr('src',u).prop('hidden',false);box.find('.mh-media__clear').prop('hidden',false);});frame.open();});\$(document).on('click','.mh-media__clear',function(e){e.preventDefault();var box=\$(this).closest('.mh-media');box.find('input').val(0);box.find('.mh-media__preview').attr('src','').prop('hidden',true);\$(this).prop('hidden',true);});});"
		);
	}

	private static function heading( string $text ): void {
		echo '</table><h2 class="title">' . esc_html( $text ) . '</h2><table class="form-table" role="presentation">';
	}

	private static function tab_general( array $s ): void {
		self::text( $s, 'brand_name', 'Newsletter name' );
		self::text( $s, 'author_name', 'Sender name', 'Shown on every issue as the email sender.' );
		self::text( $s, 'byline', 'Byline', 'Shown under the newsletter name in the header, for example "by Gaurav Tiwari". Leave blank to hide.' );
		self::text( $s, 'sender_line', 'Sender line', 'The "from" line on each issue. Default: "{Sender name} · {Newsletter name}".', 'large-text' );
		self::area( $s, 'tagline', 'Tagline', 'Used on the archive and in the subscribe box.', 2 );
		self::heading( 'Branding' );
		self::select( $s, 'header_style', 'Header shows', array( 'avatar' => 'Avatar, newsletter name and byline', 'logo_text' => 'Logo, newsletter name and byline', 'logo' => 'Logo only' ) );
		self::media( $s, 'logo_id', 'Logo', 'SVG or PNG with a transparent background works best.' );
		self::media( $s, 'logo_dark_id', 'Logo for dark mode', 'Optional. Falls back to the main logo.' );
		self::text( $s, 'logo_width', 'Logo width (px)', 'Display width in the header. Height follows the image.', 'small-text', 'number' );
		self::media( $s, 'avatar_id', 'Avatar', 'Square photo or mark shown in the header and on each issue. Falls back to the initials.' );
		self::text( $s, 'author_initials', 'Avatar initials', 'Used when no avatar image is set.', 'small-text' );
		self::heading( 'Signup' );
		self::row(
			'Newsletter page',
			wp_dropdown_pages(
				array(
					'name'              => self::name( 'landing_page_id' ),
					'id'                => 'mh-landing_page_id',
					'selected'          => (int) $s['landing_page_id'],
					'show_option_none'  => '— None —',
					'option_none_value' => 0,
					'echo'              => 0,
				)
			),
			'The signup page the header brand links to.',
			'mh-landing_page_id'
		);
		$forms = array( '' => '— None —' ) + self::core_forms();
		self::select( $s, 'form_slug', 'Signup form', $forms, 'The Core Forms form shown on issue pages and the archive. It must be registered with the site MailerPress consent policy, or signups fail closed.' );
		self::text( $s, 'form_shortcode', 'Custom form shortcode', 'Optional. Overrides the form above, for example a different form plugin.', 'large-text code' );
		self::text( $s, 'subscribe_label', 'Header button label' );
	}

	private static function tab_issues( array $s ): void {
		self::text( $s, 'issue_base', 'Issue URL base', 'Issues live at <code>' . esc_html( home_url( '/' . self::issue_base() . '/' ) ) . '{slug}/</code>. Changing this refreshes permalinks; old URLs are not redirected.', 'regular-text code' );
		self::text( $s, 'archive_title', 'Archive title', 'Default: "{Newsletter name}: every issue".' );
		self::area( $s, 'archive_intro', 'Archive intro', 'Default: the tagline.', 2 );
		self::check( $s, 'show_subscribe', 'Subscribe box', 'Show the signup box under every issue and on the archive' );
		self::text( $s, 'subscribe_heading', 'Subscribe heading', '', 'large-text' );
		self::area( $s, 'subscribe_facts', 'Subscribe facts', 'One short fact per line, shown with dot bullets under the form.', 3 );
		self::check( $s, 'show_pager', 'Issue navigation', 'Show previous and next issue links' );
		self::check( $s, 'nofollow', 'External links', 'Add rel="nofollow noopener" to every external link in issues' );
		self::check( $s, 'external_new_tab', 'Open externally', 'Open external links in a new tab' );
		self::area( $s, 'internal_hosts', 'Extra internal hosts', 'Hosts treated as internal (no nofollow). The site and its subdomains are always internal. One per line.', 3 );
	}

	private static function tab_design( array $s ): void {
		self::check( $s, 'detach_theme', 'Theme isolation', 'Detach theme styles, scripts and hooks on MailerHive pages', 'WordPress core and every plugin keep working, including Functionalities fonts, Rank Math, Core Forms and Perfmatters.' );
		self::check( $s, 'shell_mailerpress_pages', 'Subscription pages', 'Render MailerPress unsubscribe and manage-subscription pages in the MailerHive shell' );
		self::select( $s, 'color_scheme', 'Colour scheme', array( 'auto' => 'Follow the reader’s system setting', 'light' => 'Always light', 'dark' => 'Always dark' ) );
		self::check( $s, 'show_theme_toggle', 'Theme toggle', 'Show a light/dark toggle in the header', 'Starts from the reader’s system setting. A choice is remembered and shared with the main site’s toggle. Hidden when the scheme is forced to light or dark.' );
		self::text( $s, 'accent_light', 'Accent (light)', '', 'small-text', 'color' );
		self::text( $s, 'accent_dark', 'Accent (dark)', 'Used for buttons and links on dark backgrounds.', 'small-text', 'color' );
		self::area( $s, 'header_links', 'Header links', 'One per line as <code>Label | URL</code>. A Subscribe button is always added.', 3, true );
		self::text( $s, 'footer_note', 'Footer note', '', 'large-text' );
		self::area( $s, 'footer_links', 'Footer links', 'One per line as <code>Label | URL</code>.', 4, true );
		self::text( $s, 'privacy_url', 'Privacy policy URL', '', 'large-text', 'url' );
		self::area( $s, 'custom_css', 'Extra CSS', 'Loaded after MailerHive’s stylesheet on its pages only.', 6, true );
	}

	private static function list_boxes( array $s, string $key, array $lists ): string {
		$boxes = '';
		foreach ( $lists as $id => $name ) {
			$boxes .= sprintf( '<label style="display:block;margin:.2em 0"><input type="checkbox" name="%s[]" value="%d"%s> %s</label>', esc_attr( self::name( $key ) ), (int) $id, checked( in_array( (int) $id, array_map( 'intval', (array) $s[ $key ] ), true ), true, false ), esc_html( $name ) );
		}
		return $boxes ?: 'MailerPress is not active.';
	}

	private static function tab_mailerpress( array $s ): void {
		$lists  = self::mailerpress_lists();
		$sender = Email::sender();
		$state  = Settings::on( 'auto_draft' )
			? '<strong style="color:#008a20">On.</strong> Publishing a new issue creates a draft campaign in MailerPress automatically.'
			: '<strong>Off.</strong> Turn it on to get a ready-to-review MailerPress draft every time you publish an issue.';
		self::row( 'One-click email drafts', $state . self::tool_button_html( Settings::on( 'auto_draft' ) ? 'disable_auto_draft' : 'enable_auto_draft', Settings::on( 'auto_draft' ) ? 'Turn off' : 'Turn on automatic drafts', Settings::on( 'auto_draft' ) ? 'secondary' : 'primary' ) . self::tool_button_html( 'draft_latest', 'Create a draft for the latest issue', 'secondary' ), 'Drafts are never scheduled or sent. Open MailerPress → Campaigns to review and send. Every draft includes Unsubscribe and Manage subscription links, which MailerPress fills in per recipient. No MailerPress Automation is needed.' );
		echo '<tr><td colspan="2" style="padding:0"><input type="hidden" name="' . esc_attr( self::name( 'auto_draft' ) ) . '" value="' . ( Settings::on( 'auto_draft' ) ? '1' : '' ) . '"></td></tr>';
		self::row( 'Send drafts to', self::list_boxes( $s, 'draft_list_ids', $lists ), 'Leave empty to use the mirror lists below.' );
		self::text( $s, 'subject_template', 'Subject line', 'Placeholders: <code>{title}</code>, <code>{newsletter}</code>.', 'regular-text' );
		self::text( $s, 'sender_name', 'From name', 'Default from MailerPress: ' . esc_html( $sender['name'] ) . '.' );
		self::text( $s, 'sender_email', 'From email', 'Default from MailerPress: ' . esc_html( $sender['email'] ) . '.', 'regular-text', 'email' );
		self::text( $s, 'email_footer', 'Email footer note', 'Shown above the Unsubscribe and Manage subscription links.', 'large-text' );
		self::heading( 'Campaigns written in MailerPress' );
		self::check( $s, 'mirror_enabled', 'Mirror campaigns', 'Publish newsletters composed directly in MailerPress as issues once they are sent' );
		self::row( 'Mirror lists', self::list_boxes( $s, 'mirror_list_ids', $lists ), 'Only campaigns sent to at least one of these lists are mirrored. Customer and transactional sends stay private.' );
		self::select( $s, 'mirror_status', 'Mirrored issue status', array( 'publish' => 'Publish immediately', 'draft' => 'Save as draft for review' ) );
		self::check( $s, 'redirect_web_view', 'View in browser', 'Send MailerPress "view in browser" links to the matching issue page' );
		$mp = get_option( 'mailerpress_default_settings' );
		$mp = is_string( $mp ) ? json_decode( $mp, true ) : $mp;
		$fn = static function ( $page ) {
			if ( empty( $page ) || ! empty( $page['useDefault'] ) || empty( $page['pageId'] ) ) {
				return 'MailerPress default page';
			}
			return esc_html( get_the_title( (int) $page['pageId'] ) ) . ' (#' . (int) $page['pageId'] . ')';
		};
		self::row( 'Subscription pages', 'Unsubscribe: ' . $fn( $mp['unsubpage'] ?? null ) . '<br>Manage: ' . $fn( $mp['subpage'] ?? null ), 'Set in MailerPress → Settings. MailerHive renders them in its shell when enabled on the Design tab.' );
	}

	/** A tool button rendered inside the settings form (submits to admin-post via formaction). */
	private static function tool_button_html( string $tool, string $label, string $class ): string {
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'mailerhive_tool', 'tool' => $tool ), admin_url( 'admin-post.php' ) ), 'mailerhive_tool' );
		return ' <p style="margin:.6em 0 0"><a class="button button-' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
	}

	private static function tab_analytics( array $s ): void {
		self::check( $s, 'rybbit_enabled', 'Rybbit', 'Load the Rybbit tracking script on MailerHive pages', 'The site’s Rybbit tag comes from the theme’s footer scripts, which theme isolation detaches, so MailerHive prints its own copy.' );
		self::text( $s, 'rybbit_src', 'Script URL', '', 'large-text code', 'url' );
		self::text( $s, 'rybbit_site_id', 'Site ID', '', 'regular-text code' );
		self::area( $s, 'head_html', 'Extra head HTML', 'Optional tags printed in the &lt;head&gt; of MailerHive pages, such as another analytics or verification tag.', 4, true );
	}

	private static function tools_tab(): void {
		$counts = array();
		foreach ( get_posts( array( 'post_type' => Issues::TYPE, 'post_status' => array( 'publish', 'draft', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
			$src            = (string) get_post_meta( $id, Issues::META_SOURCE, true ) ?: 'wp';
			$counts[ $src ] = ( $counts[ $src ] ?? 0 ) + 1;
		}
		$where = static function ( string $class ): string {
			if ( ! class_exists( $class, false ) ) {
				return '<span style="color:#b32d2e">not loaded</span>';
			}
			$file = wp_normalize_path( (string) ( new \ReflectionClass( $class ) )->getFileName() );
			return str_contains( $file, '/plugins/mailerhive/' ) ? 'MailerHive' : 'old MU plugin';
		};
		$last = get_option( self::SYNC_OPTION, array() );
		$next = wp_next_scheduled( MailerPress_Bridge::SYNC_HOOK );
		$mpv  = defined( 'MAILERPRESS_VERSION' ) ? MAILERPRESS_VERSION : ( MailerPress_Bridge::available() ? 'active' : 'not active' );
		$rows = array(
			'MailerPress'                 => esc_html( (string) $mpv ),
			'Consent policy module'       => $where( 'GT_Core_Forms_MailerPress_Policy' ),
			'FluentCart lifecycle module' => $where( 'GT_MailerPress_FluentCart_Lifecycle' ),
			'Issues'                      => $counts ? esc_html( implode( ', ', array_map( static fn( $k, $v ) => "{$v} {$k}", array_keys( $counts ), $counts ) ) ) : '0',
			'Issue URLs'                  => '<code>' . esc_html( home_url( '/' . self::issue_base() . '/{slug}/' ) ) . '</code>',
			'Last campaign sync'          => $last ? esc_html( sprintf( '%s: %d linked, %d published', wp_date( 'M j, Y H:i', (int) $last['time'] ), $last['linked'], $last['mirrored'] ) ) : 'never',
			'Next scheduled sync'         => $next ? esc_html( wp_date( 'M j, Y H:i', $next ) ) : 'not scheduled',
		);
		echo '<h2>Status</h2><table class="widefat striped" style="max-width:820px"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th style="width:240px">' . esc_html( $k ) . '</th><td>' . $v . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		echo '</tbody></table>';

		$button = static function ( string $tool, string $label, string $help, string $extra = '' ): void {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:1em 0">';
			wp_nonce_field( 'mailerhive_tool' );
			echo '<input type="hidden" name="action" value="mailerhive_tool"><input type="hidden" name="tool" value="' . esc_attr( $tool ) . '">';
			echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
			submit_button( $label, 'secondary', 'submit', false );
			echo ' <span class="description">' . esc_html( $help ) . '</span></form>';
		};
		echo '<h2>Tools</h2>';
		$button( 'sync', 'Sync MailerPress now', 'Link drafted campaigns to their issues and publish sent newsletters.' );
		$button( 'relink', 'Relink imported issues', 'Point links between imported Substack issues at their local pages.' );
		$button( 'flush', 'Refresh permalinks', 'Use after changing the issue URL base.' );
		$button( 'seed', 'Re-detect settings', 'Fill blank settings from the site. Saved values are kept.' );
		$button(
			'import',
			'Import from Substack',
			'Images are copied into the media library. Already-imported posts are skipped.',
			'<p><label>Publication subdomain <input name="publication" value="gauravtiwari" class="regular-text code"></label></p><p><label>Post slugs, one per line<br><textarea name="slugs" rows="4" class="large-text code"></textarea></label></p>'
		);
	}
}
