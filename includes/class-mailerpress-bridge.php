<?php
/**
 * MailerPress bridge.
 *
 * - "View in browser" links (/mp-email/{id}-{slug}/) 301 to the matching issue page.
 * - Campaigns that MailerPress's "Post Published" workflow drafts from an issue are
 *   linked back to that issue (config.publicationPostId).
 * - Newsletters composed directly in MailerPress and sent to a mirrored list are
 *   published as issue pages, once.
 *
 * Nothing here sends, schedules or edits a campaign. MailerPress owns delivery,
 * unsubscribe/manage links and the List-Unsubscribe header.
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- MailerPress and Core Forms keep their data in custom tables without a public read API; table names come from $wpdb->prefix and values are prepared.

final class MailerPress_Bridge {
	const SYNC_HOOK = 'mailerhive_sync_campaigns';

	public static function init(): void {
		add_action( 'wp_loaded', array( __CLASS__, 'redirect_web_view' ), 0 );
		add_action( self::SYNC_HOOK, array( __CLASS__, 'sync' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function available(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'mailerpress_campaigns';
		return class_exists( '\MailerPress\Core\Kernel' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::SYNC_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::SYNC_HOOK );
		}
	}

	public static function issue_for_campaign( int $campaign_id ): ?\WP_Post {
		$ids = get_posts(
			array(
				'post_type'      => Issues::TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => Issues::META_CAMPAIGN, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $campaign_id,          // phpcs:ignore WordPress.DB.SlowDBQuery
				'no_found_rows'  => true,
			)
		);
		return $ids ? get_post( $ids[0] ) : null;
	}

	public static function redirect_web_view(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$path = trim( (string) strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( $home && str_starts_with( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}
		if ( ! preg_match( '#^mp-email/(\d+)-[^/]*$#', $path, $m ) ) {
			return;
		}
		$campaign_id = (int) $m[1];
		$issue       = self::issue_for_campaign( $campaign_id );
		if ( self::campaign_is_paid_only( $campaign_id ) ) {
			self::guard_paid_web_view( $campaign_id, $issue );
		}
		if ( ! Settings::on( 'redirect_web_view' ) ) {
			return;
		}
		if ( $issue && 'publish' === $issue->post_status ) {
			wp_safe_redirect( get_permalink( $issue ), 301 );
			exit;
		}
	}

	/**
	 * A sent campaign that went only to paid lists must not stay on the MailerPress web view.
	 * Mirror it onto a published, paywalled issue, or refuse the page.
	 */
	private static function guard_paid_web_view( int $campaign_id, ?\WP_Post $issue ): void {
		if ( $issue && 'publish' === $issue->post_status ) {
			wp_safe_redirect( get_permalink( $issue ), 301 );
			exit;
		}
		if ( ! $issue ) {
			$created = self::mirror_paid_now( $campaign_id );
			$issue   = $created ? get_post( $created ) : null;
			if ( $issue && 'publish' === $issue->post_status ) {
				wp_safe_redirect( get_permalink( $issue ), 302 );
				exit;
			}
		}
		status_header( 403 );
		nocache_headers();
		wp_die( esc_html__( 'This issue is for paid subscribers.', 'mailerhive' ), esc_html__( 'Subscribers only', 'mailerhive' ), array( 'response' => 403 ) );
	}

	private static function mirror_paid_now( int $campaign_id ): int {
		$lock = 'mailerhive_mirror_lock_' . $campaign_id;
		if ( ! add_option( $lock, time(), '', false ) ) {
			$existing = self::issue_for_campaign( $campaign_id );
			return ( $existing && 'publish' === $existing->post_status ) ? (int) $existing->ID : 0;
		}
		try {
			return self::mirror( $campaign_id, 'publish' );
		} finally {
			delete_option( $lock );
		}
	}

	/** Sent, intersects a paid tier list, and does not intersect a free mirror list. */
	private static function campaign_is_paid_only( int $campaign_id ): bool {
		if ( ! class_exists( '\MailerHive\Modules\Paid\Plans' ) ) {
			return false;
		}
		global $wpdb;
		$wpdb->last_error = '';
		$row              = $wpdb->get_row( $wpdb->prepare( "SELECT status, config FROM {$wpdb->prefix}mailerpress_campaigns WHERE campaign_id = %d", $campaign_id ) );
		if ( $wpdb->last_error || ! $row || 'sent' !== (string) $row->status ) {
			return false;
		}
		$lists = self::list_ids( self::config( $row ) );
		$paid  = \MailerHive\Modules\Paid\Plans::tier_list_ids();
		$free  = array_map( 'intval', (array) Settings::get( 'mirror_list_ids' ) );
		return (bool) array_intersect( $lists, $paid ) && ! array_intersect( $lists, $free );
	}

	private static function config( $row ): array {
		$cfg = json_decode( (string) ( $row->config ?? '' ), true );
		return is_array( $cfg ) ? $cfg : array();
	}

	private static function list_ids( array $cfg ): array {
		$ids = array();
		foreach ( (array) ( $cfg['lists'] ?? array() ) as $item ) {
			$v = is_array( $item ) ? ( $item['list_id'] ?? $item['id'] ?? null ) : $item;
			if ( is_numeric( $v ) ) {
				$ids[] = (int) $v;
			}
		}
		return $ids;
	}

	/**
	 * Link workflow-drafted campaigns to their issue, then mirror sent newsletters.
	 *
	 * @return array{linked:int,mirrored:int}
	 */
	public static function sync(): array {
		$result = array( 'linked' => 0, 'mirrored' => 0 );
		if ( ! self::available() ) {
			return $result;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'mailerpress_campaigns';

		$rows = $wpdb->get_results( "SELECT campaign_id, config FROM {$table} WHERE config LIKE '%publicationPostId%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $row ) {
			$post_id = (int) ( self::config( $row )['publicationPostId'] ?? 0 );
			if ( $post_id && Issues::TYPE === get_post_type( $post_id ) && ! get_post_meta( $post_id, Issues::META_CAMPAIGN, true ) ) {
				update_post_meta( $post_id, Issues::META_CAMPAIGN, (int) $row->campaign_id );
				++$result['linked'];
			}
		}

		$lists = array_map( 'intval', (array) apply_filters( 'mailerhive_mirror_list_ids', (array) Settings::get( 'mirror_list_ids' ) ) );
		if ( ! $lists || ! Settings::on( 'mirror_enabled' ) ) {
			return self::record( $result );
		}
		$sent = $wpdb->get_results(
			"SELECT campaign_id, name, subject, config, updated_at FROM {$table}
			 WHERE status = 'sent' AND campaign_type = 'newsletter' AND automation_id IS NULL
			 ORDER BY campaign_id ASC" // phpcs:ignore WordPress.DB.PreparedSQL
		);
		foreach ( $sent as $row ) {
			$cfg = self::config( $row );
			if ( ! empty( $cfg['publicationPostId'] ) || ! array_intersect( $lists, self::list_ids( $cfg ) ) ) {
				continue;
			}
			if ( self::issue_for_campaign( (int) $row->campaign_id ) ) {
				continue;
			}
			if ( self::mirror( (int) $row->campaign_id, 'draft' === Settings::get( 'mirror_status' ) ? 'draft' : 'publish' ) ) {
				++$result['mirrored'];
			}
		}
		return self::record( $result );
	}

	private static function record( array $result ): array {
		update_option( Settings::SYNC_OPTION, $result + array( 'time' => time() ), false );
		return $result;
	}

	/**
	 * Publish one MailerPress campaign as an issue. Returns the post ID or 0.
	 */
	public static function mirror( int $campaign_id, string $status = 'publish', array $overrides = array() ): int {
		global $wpdb;
		$existing = self::issue_for_campaign( $campaign_id );
		if ( $existing ) {
			return (int) $existing->ID;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mailerpress_campaigns WHERE campaign_id = %d", $campaign_id ) );
		if ( ! $row ) {
			return 0;
		}
		$html = (string) get_option( 'mailerpress_batch_' . $campaign_id . '_html', '' );
		if ( '' === $html ) {
			return 0;
		}
		$cfg     = self::config( $row );
		$title   = $overrides['title'] ?? ( $row->subject ?: $row->name );
		$content = Content::from_email( $html );
		$date    = $overrides['date'] ?? (string) $row->updated_at;

		$post_id = wp_insert_post(
			array(
				'post_type'     => Issues::TYPE,
				'post_status'   => 'draft',
				'post_title'    => wp_strip_all_tags( $title ),
				'post_name'     => sanitize_title( $overrides['slug'] ?? $title ),
				'post_content'  => '',
				'post_excerpt'  => (string) ( $cfg['previewText'] ?? '' ),
				'post_date'     => get_date_from_gmt( $date ),
				'post_date_gmt' => $date,
				'meta_input'    => array(
					Issues::META_SOURCE   => 'mailerpress',
					Issues::META_CAMPAIGN => $campaign_id,
				),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		do_action( 'mailerhive_issue_mirrored', (int) $post_id, self::list_ids( $cfg ) );
		$content = Content::import_images( $content, (int) $post_id );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
					'post_status'  => $status,
				)
			)
		);
		return (int) $post_id;
	}
}
