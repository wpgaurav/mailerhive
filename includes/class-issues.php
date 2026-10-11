<?php
/**
 * Newsletter issues: the mh_issue post type at /newsletter/issue/{slug}/, its archive
 * at /newsletter/issue/, issue meta, and the [mailerhive_issues] list shortcode.
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

final class Issues {
	const TYPE = 'mh_issue';

	const META_SUBTITLE   = '_mh_subtitle';
	const META_SOURCE     = '_mh_source';      // wp | substack | mailerpress
	const META_SOURCE_URL = '_mh_source_url';
	const META_CAMPAIGN   = '_mh_campaign_id';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_shortcode( 'mailerhive_issues', array( __CLASS__, 'shortcode' ) );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => 'Newsletter Issues',
					'singular_name' => 'Newsletter Issue',
					'add_new_item'  => 'Write New Issue',
					'edit_item'     => 'Edit Issue',
					'view_item'     => 'View Issue',
					'all_items'     => 'All Issues',
					'search_items'  => 'Search Issues',
					'menu_name'     => 'Newsletter',
				),
				'public'              => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-email-alt',
				'menu_position'       => 26,
				'has_archive'         => Settings::issue_base(),
				'rewrite'             => array(
					'slug'       => Settings::issue_base(),
					'with_front' => false,
					'feeds'      => true,
					'pages'      => true,
				),
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author', 'custom-fields' ),
				'exclude_from_search' => false,
			)
		);

		foreach ( array( self::META_SUBTITLE, self::META_SOURCE, self::META_SOURCE_URL ) as $key ) {
			register_post_meta(
				self::TYPE,
				$key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => self::META_SUBTITLE === $key,
					'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
				)
			);
		}
		register_post_meta(
			self::TYPE,
			self::META_CAMPAIGN,
			array(
				'type'          => 'integer',
				'single'        => true,
				'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
			)
		);
	}

	public static function subtitle( $post = null ): string {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$sub = (string) get_post_meta( $post->ID, self::META_SUBTITLE, true );
		return '' !== $sub ? $sub : (string) $post->post_excerpt;
	}

	/**
	 * Previous (older) and next (newer) published issues.
	 */
	public static function neighbours( \WP_Post $post ): array {
		$q = static function ( string $cmp, string $order ) use ( $post ) {
			$ids = get_posts(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => $order,
					'fields'         => 'ids',
					'date_query'     => array( array( $cmp => $post->post_date, 'inclusive' => false ) ),
					'no_found_rows'  => true,
				)
			);
			return $ids ? get_post( $ids[0] ) : null;
		};
		return array(
			'older' => $q( 'before', 'DESC' ),
			'newer' => $q( 'after', 'ASC' ),
		);
	}

	/**
	 * [mailerhive_issues limit="6"] renders the recent-issue rows used on the newsletter
	 * page. Markup matches that page's .nl-issue styles so the section stays as designed.
	 */
	public static function shortcode( $atts ): string {
		$atts  = shortcode_atts( array( 'limit' => 6 ), $atts, 'mailerhive_issues' );
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, (int) $atts['limit'] ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		if ( ! $posts ) {
			return '';
		}
		$arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12l14 0"/><path d="M13 18l6 -6"/><path d="M13 6l6 6"/></svg>';
		$out   = '<ol class="nl-issues__list" role="list">';
		foreach ( $posts as $p ) {
			$out .= sprintf(
				'<li><a class="nl-issue" href="%1$s"><time datetime="%2$s">%3$s</time><span class="nl-issue__main"><span class="nl-issue__t">%4$s</span><span class="nl-issue__d">%5$s</span></span><span class="nl-issue__go">%6$s</span></a></li>',
				esc_url( get_permalink( $p ) ),
				esc_attr( get_the_date( 'Y-m-d', $p ) ),
				esc_html( get_the_date( 'M j, Y', $p ) ),
				esc_html( get_the_title( $p ) ),
				esc_html( wp_trim_words( self::subtitle( $p ), 22 ) ),
				$arrow
			);
		}
		return $out . '</ol>';
	}

	public static function meta_box(): void {
		add_meta_box( 'mailerhive-issue', 'Issue details', array( __CLASS__, 'render_meta_box' ), self::TYPE, 'side' );
	}

	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'mailerhive_issue', 'mailerhive_issue_nonce' );
		$campaign = (int) get_post_meta( $post->ID, self::META_CAMPAIGN, true );
		$source   = (string) get_post_meta( $post->ID, self::META_SOURCE, true );
		printf(
			'<p><label for="mh-subtitle"><strong>Subtitle</strong></label><textarea id="mh-subtitle" name="mh_subtitle" rows="3" style="width:100%%">%s</textarea></p>',
			esc_textarea( (string) get_post_meta( $post->ID, self::META_SUBTITLE, true ) )
		);
		echo '<p>Source: ' . esc_html( $source ? $source : 'wp' ) . '</p>';
		if ( $campaign ) {
			printf( '<p><a class="button" href="%s">Open MailerPress campaign #%d</a></p>', esc_url( Email::edit_url( $campaign ) ), $campaign );
		} elseif ( 'publish' === $post->post_status ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'mailerhive_draft', 'post' => $post->ID ), admin_url( 'admin-post.php' ) ), 'mailerhive_draft_' . $post->ID );
			printf( '<p><a class="button button-primary" href="%s">Create MailerPress draft</a></p><p class="description">Creates a draft campaign to review and send. Nothing is sent automatically.</p>', esc_url( $url ) );
		} else {
			echo '<p class="description">' . esc_html( Settings::on( 'auto_draft' ) ? 'Publishing creates a MailerPress draft automatically.' : 'After publishing, use "Create MailerPress draft" here, or turn on automatic drafts in Newsletter → Settings → MailerPress.' ) . '</p>';
		}
	}

	public static function save_meta_box( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['mailerhive_issue_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['mailerhive_issue_nonce'] ), 'mailerhive_issue' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['mh_subtitle'] ) ) {
			update_post_meta( $post_id, self::META_SUBTITLE, sanitize_textarea_field( wp_unslash( $_POST['mh_subtitle'] ) ) );
		}
		if ( ! get_post_meta( $post_id, self::META_SOURCE, true ) ) {
			update_post_meta( $post_id, self::META_SOURCE, 'wp' );
		}
	}

	public static function columns( array $cols ): array {
		$cols['mh_source'] = 'Source';
		return $cols;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'mh_source' === $col ) {
			$campaign = (int) get_post_meta( $post_id, self::META_CAMPAIGN, true );
			echo esc_html( (string) get_post_meta( $post_id, self::META_SOURCE, true ) ?: 'wp' );
			if ( $campaign ) {
				echo ' &middot; campaign #' . (int) $campaign;
			}
		}
	}
}
