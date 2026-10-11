<?php
/**
 * One-click MailerPress drafts. When an issue written in WordPress is published
 * (or when "Create MailerPress draft" is clicked), MailerHive renders it as an email
 * and creates a DRAFT campaign through MailerPress's own campaign API, addressed to
 * the configured lists. Nothing is scheduled or sent: the campaign waits in
 * MailerPress → Campaigns for review. The email always carries %UNSUB_LINK% and
 * %MANAGE_SUB_LINK%, which MailerPress replaces per recipient at send time.
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- MailerPress and Core Forms keep their data in custom tables without a public read API; table names come from $wpdb->prefix and values are prepared.

final class Email {
	public static function init(): void {
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_publish' ), 20, 4 );
		add_action( 'admin_post_mailerhive_draft', array( __CLASS__, 'handle_button' ) );
	}

	public static function edit_url( int $campaign_id ): string {
		return admin_url( 'admin.php?page=mailerpress/new&edit=' . $campaign_id );
	}

	public static function ready(): bool {
		return MailerPress_Bridge::available()
			&& class_exists( '\MailerPress\Api\Campaigns' )
			&& class_exists( '\MailerPress\Services\CampaignHtmlOptionStorage' );
	}

	/** Lists the drafts are addressed to (falls back to the mirror lists). */
	public static function list_ids(): array {
		$ids = array_values( array_filter( array_map( 'intval', (array) Settings::get( 'draft_list_ids' ) ) ) );
		return $ids ?: array_values( array_filter( array_map( 'intval', (array) Settings::get( 'mirror_list_ids' ) ) ) );
	}

	public static function sender(): array {
		$d = get_option( 'mailerpress_default_settings' );
		$d = is_string( $d ) ? json_decode( $d, true ) : ( is_array( $d ) ? $d : array() );
		return array(
			'name'  => (string) ( Settings::get( 'sender_name' ) ?: ( $d['fromName'] ?? Settings::author() ) ),
			'email' => (string) ( Settings::get( 'sender_email' ) ?: ( $d['fromAddress'] ?? get_option( 'admin_email' ) ) ),
		);
	}

	public static function on_publish( int $post_id, \WP_Post $post, bool $update, $post_before ): void {
		if ( Issues::TYPE !== $post->post_type || 'publish' !== $post->post_status || ! Settings::on( 'auto_draft' ) ) {
			return;
		}
		if ( $post_before instanceof \WP_Post && 'publish' === $post_before->post_status ) {
			return; // only the first publish
		}
		if ( 'wp' !== ( (string) get_post_meta( $post_id, Issues::META_SOURCE, true ) ?: 'wp' ) ) {
			return; // imported issues were already sent elsewhere
		}
		self::create_draft( $post_id );
	}

	public static function handle_button(): void {
		$post_id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'mailerhive_draft_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Not allowed.' );
		}
		$r = self::create_draft( $post_id );
		set_transient( 'mailerhive_notice_' . get_current_user_id(), $r['message'], 60 );
		wp_safe_redirect( $r['campaign_id'] ? self::edit_url( $r['campaign_id'] ) : get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	/**
	 * @return array{ok:bool,campaign_id:int,message:string}
	 */
	public static function create_draft( int $post_id ): array {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post || Issues::TYPE !== $post->post_type ) {
			return array( 'ok' => false, 'campaign_id' => 0, 'message' => 'Not a newsletter issue.' );
		}
		if ( ! self::ready() ) {
			return array( 'ok' => false, 'campaign_id' => 0, 'message' => 'MailerPress is not active.' );
		}
		$existing = (int) get_post_meta( $post_id, Issues::META_CAMPAIGN, true );
		if ( $existing && $wpdb->get_var( $wpdb->prepare( "SELECT campaign_id FROM {$wpdb->prefix}mailerpress_campaigns WHERE campaign_id = %d AND status <> 'trash'", $existing ) ) ) {
			return array( 'ok' => true, 'campaign_id' => $existing, 'message' => 'This issue already has a MailerPress campaign.' );
		}
		$lists = array_values( array_filter( array_map( 'intval', (array) apply_filters( 'mailerhive_draft_list_ids', self::list_ids(), $post ) ) ) );
		if ( ! $lists ) {
			return array( 'ok' => false, 'campaign_id' => 0, 'message' => 'This issue has no MailerPress list to go to. Choose lists on the MailerPress tab, or give its paid tier a list on the Paid tab.' );
		}

		$html    = self::render( $post );
		$sender  = self::sender();
		$title   = wp_strip_all_tags( get_the_title( $post ) );
		$subject = str_replace( array( '{title}', '{newsletter}' ), array( $title, Settings::brand() ), (string) ( Settings::get( 'subject_template' ) ?: '{title}' ) );
		$config  = array(
			'campaignName'       => Settings::brand() . ': ' . $title,
			'campaignSubject'    => $subject,
			'email_type'         => 'html',
			'editorType'         => 'html',
			'sendChoice'         => 'now',
			'lists'              => array_map( static fn( $id ) => array( 'list_id' => $id ), $lists ),
			'recipientTargeting' => 'classic',
			'tags'               => array(),
			'segment'            => array(),
			'fromName'           => $sender['name'],
			'fromTo'             => $sender['email'],
			'previewText'        => Issues::subtitle( $post ),
			'openTracking'       => 'anonymously',
			'clickTracking'      => 'anonymously',
			'mailerhiveIssueId'  => $post_id,
		);

		$request = new \WP_REST_Request( 'POST' );
		$request->set_body_params(
			array(
				'title'         => $config['campaignName'],
				'campaign_type' => 'newsletter',
				'meta'          => array( 'json' => $html, 'emailConfig' => $config ),
			)
		);
		$response = ( new \MailerPress\Api\Campaigns() )->post( $request );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'campaign_id' => 0, 'message' => $response->get_error_message() );
		}
		$campaign_id = (int) ( $response instanceof \WP_REST_Response ? $response->get_data() : 0 );
		if ( ! $campaign_id ) {
			return array( 'ok' => false, 'campaign_id' => 0, 'message' => 'MailerPress did not return a campaign.' );
		}
		$stored = \MailerPress\Services\CampaignHtmlOptionStorage::store( $campaign_id, $html, array( 'source' => 'mailerhive' ) );
		if ( empty( $stored['success'] ) ) {
			return array( 'ok' => false, 'campaign_id' => $campaign_id, 'message' => 'The campaign was created but its HTML could not be saved.' );
		}
		if ( ! get_current_user_id() ) {
			$wpdb->update( $wpdb->prefix . 'mailerpress_campaigns', array( 'user_id' => (int) $post->post_author ), array( 'campaign_id' => $campaign_id ) );
		}
		update_post_meta( $post_id, Issues::META_CAMPAIGN, $campaign_id );
		return array( 'ok' => true, 'campaign_id' => $campaign_id, 'message' => 'Draft campaign created in MailerPress. Review it there and send when ready.' );
	}

	/**
	 * Table-based email that holds up in Gmail, Apple Mail and Outlook.
	 */
	public static function render( \WP_Post $post ): string {
		$accent  = sanitize_hex_color( (string) Settings::get( 'accent_light' ) ) ?: '#C0392B';
		$brand   = esc_html( Settings::brand() );
		$title   = esc_html( wp_strip_all_tags( get_the_title( $post ) ) );
		$sub     = esc_html( Issues::subtitle( $post ) );
		$url     = esc_url( get_permalink( $post ) );
		$date    = esc_html( get_the_date( 'F j, Y', $post ) );
		$footer  = esc_html( str_replace( array( '{newsletter}', '{site}' ), array( Settings::brand(), get_bloginfo( 'name' ) ), (string) Settings::get( 'email_footer' ) ) );
		$content = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$content = preg_replace( '#<(script|style|iframe|form)\b.*?</\1>#is', '', (string) $content );
		$content = preg_replace( '#<img\b#i', '<img style="max-width:100%;height:auto;border-radius:8px;display:block"', $content );
		$content = preg_replace( '#<a\b(?![^>]*style=)#i', '<a style="color:' . $accent . '"', $content );
		$content = preg_replace( '#<(h2)\b[^>]*>#i', '<h2 style="font-size:22px;line-height:1.3;margin:28px 0 10px;color:#212121">', $content );
		$content = preg_replace( '#<(h3)\b[^>]*>#i', '<h3 style="font-size:18px;line-height:1.35;margin:24px 0 8px;color:#212121">', $content );
		$content = preg_replace( '#<p\b(?![^>]*style=)#i', '<p style="margin:0 0 16px"', $content );
		$content = preg_replace( '#<blockquote\b[^>]*>#i', '<blockquote style="margin:0 0 16px;padding:12px 16px;background:#F7EFE5;border-radius:8px;font-style:italic">', $content );
		$logo    = (int) Settings::get( 'logo_id' );
		$logo    = $logo ? wp_get_attachment_image_src( $logo, 'full' ) : false;
		$head    = $logo
			? '<img src="' . esc_url( $logo[0] ) . '" width="' . (int) min( 160, (int) Settings::get( 'logo_width' ) ?: 140 ) . '" alt="' . $brand . '" style="display:block;height:auto;border:0">'
			: '<span style="font-size:15px;font-weight:600;color:#212121">' . $brand . '</span>';

		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title></head>'
			. '<body style="margin:0;padding:0;background:#F7EFE5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;color:#2A2A2A">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7EFE5"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#FFFFFE;border-radius:14px">'
			. '<tr><td style="padding:20px 32px;border-bottom:1px solid #E7E1D9"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td>' . $head . '</td><td align="right" style="font-size:12px;color:#5C5852">' . $date . '</td></tr></table></td></tr>'
			. '<tr><td style="padding:28px 32px 4px"><h1 style="margin:0;font-size:28px;line-height:1.2;color:#212121">' . $title . '</h1>'
			. ( $sub ? '<p style="margin:10px 0 0;font-size:17px;line-height:1.55;color:#5C5852;font-style:italic">' . $sub . '</p>' : '' )
			. '<p style="margin:14px 0 0;font-size:13px"><a href="' . $url . '" style="color:' . $accent . '">' . esc_html__( 'Read this issue on the web', 'mailerhive' ) . '</a></p></td></tr>'
			. '<tr><td style="padding:20px 32px 28px;font-size:16px;line-height:1.7">' . $content . '</td></tr>'
			. '</table>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px"><tr><td align="center" style="padding:20px 24px;font-size:12px;line-height:1.6;color:#5C5852">'
			. ( $footer ? '<p style="margin:0 0 8px;font-style:italic">' . $footer . '</p>' : '' )
			. '<p style="margin:0"><a href="%UNSUB_LINK%" style="color:#5C5852">' . esc_html__( 'Unsubscribe', 'mailerhive' ) . '</a> | <a href="%MANAGE_SUB_LINK%" style="color:#5C5852">' . esc_html__( 'Manage subscription', 'mailerhive' ) . '</a></p>'
			. '</td></tr></table>'
			. '</td></tr></table></body></html>';
	}
}
