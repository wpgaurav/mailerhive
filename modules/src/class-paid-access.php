<?php
/**
 * Who may read a paid issue, and what everyone else sees.
 *
 * The public URL of a paid issue always renders the same locked page (preview, then
 * the plans), so page and edge caches can keep serving it. Readers are recognized by:
 *
 * - their WordPress login (and any FluentCart customer record linked to it), or
 * - a signed reader cookie, set by a one-time reading link emailed on request.
 *
 * A recognized member gets the full issue rendered with no-store headers, or, when a
 * cached locked page reaches them, the page swaps in the full text from admin-ajax,
 * which is never cached. Feeds and the REST API only ever carry the preview.
 */

namespace MailerHive\Modules\Paid;

use MailerHive\Icons;
use MailerHive\Issues;
use MailerHive\Settings;
use MailerHive\Shell;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FluentCart customer lookup; table name from $wpdb->prefix, values prepared.

final class Access {
	const META        = '_mh_access';
	const COOKIE      = 'mh_reader';
	const HINT_COOKIE = 'mh_member';
	const LINK_ARG    = 'mh_access';
	const LINK_TTL    = HOUR_IN_SECONDS;
	const USED_PREFIX = 'mailerhive_used_';

	private static $rank = null;

	/** Stops preview rendering from running the paywall filters again. */
	private static $in_preview = false;

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'template_redirect', array( __CLASS__, 'consume_link' ), 1 );
		add_action( Members::SWEEP_HOOK, array( __CLASS__, 'purge_spent_links' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_store_for_members' ), 20 );
		add_filter( 'mailerhive_issue_body', array( __CLASS__, 'issue_body' ), 10, 2 );
		add_filter( 'mailerhive_issue_badge', array( __CLASS__, 'badge' ), 10, 2 );
		add_filter( 'the_content_feed', array( __CLASS__, 'feed_content' ), 99 );
		add_filter( 'the_excerpt_rss', array( __CLASS__, 'feed_content' ), 99 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'guard_excerpt_early' ), 9, 2 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'guard_excerpt' ), 11, 2 );
		add_filter( 'the_content', array( __CLASS__, 'guard_secondary_content' ), 8 );
		add_filter( 'rest_prepare_' . Issues::TYPE, array( __CLASS__, 'rest_content' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'schema' ), 5 );
		add_action( 'wp_ajax_mailerhive_unlock', array( __CLASS__, 'ajax_unlock' ) );
		add_action( 'wp_ajax_nopriv_mailerhive_unlock', array( __CLASS__, 'ajax_unlock' ) );
		add_action( 'wp_ajax_mailerhive_prices', array( __CLASS__, 'ajax_prices' ) );
		add_action( 'wp_ajax_nopriv_mailerhive_prices', array( __CLASS__, 'ajax_prices' ) );
		add_action( 'admin_post_mailerhive_reader_link', array( __CLASS__, 'request_link' ) );
		add_action( 'admin_post_nopriv_mailerhive_reader_link', array( __CLASS__, 'request_link' ) );
		add_action( 'admin_post_mailerhive_reader_signout', array( __CLASS__, 'sign_out' ) );
		add_action( 'admin_post_nopriv_mailerhive_reader_signout', array( __CLASS__, 'sign_out' ) );
		add_action( 'wp_login', array( __CLASS__, 'hint_on_login' ), 10, 2 );
		add_action( 'mailerhive_landing_after_hero', array( __CLASS__, 'landing_plans' ) );
		add_shortcode( 'mailerhive_plans', array( __CLASS__, 'plans_shortcode' ) );
		add_shortcode( 'mailerhive_account', array( __CLASS__, 'account_shortcode' ) );
	}

	public static function register_meta(): void {
		register_post_meta(
			Issues::TYPE,
			self::META,
			array(
				'type'          => 'string',
				'single'        => true,
				'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
			)
		);
	}

	/* ---------- issue access ---------- */

	/** Tier key an issue requires, '' when free (or its tier no longer exists). */
	public static function required( $post ): string {
		$post = get_post( $post );
		if ( ! $post || Issues::TYPE !== $post->post_type ) {
			return '';
		}
		$key = (string) get_post_meta( $post->ID, self::META, true );
		return $key && Plans::rank( $key ) ? $key : '';
	}

	public static function is_paid( $post ): bool {
		return '' !== self::required( $post );
	}

	public static function can_read( $post ): bool {
		$need = self::required( $post );
		if ( '' === $need ) {
			return true;
		}
		$post = get_post( $post );
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			return true;
		}
		return self::viewer_rank() >= Plans::rank( $need );
	}

	public static function viewer_rank(): int {
		if ( null === self::$rank ) {
			self::$rank = Members::rank_for( self::viewer_emails() );
		}
		return self::$rank;
	}

	/** Emails that identify the current visitor. */
	public static function viewer_emails(): array {
		global $wpdb;
		$emails = array();
		$user   = wp_get_current_user();
		if ( $user && $user->exists() ) {
			$emails[] = $user->user_email;
			if ( Plans::fluentcart_ready() ) {
				foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT email FROM {$wpdb->prefix}fct_customers WHERE user_id = %d", $user->ID ) ) as $e ) {
					$emails[] = $e;
				}
			}
		}
		$cookie = self::cookie_email();
		if ( $cookie ) {
			$emails[] = $cookie;
		}
		return array_values( array_unique( array_map( 'strtolower', array_filter( $emails ) ) ) );
	}

	/* ---------- signed tokens ---------- */

	private static function sign( string $email, int $expires, string $purpose ): string {
		$payload = rtrim( strtr( base64_encode( strtolower( $email ) . '|' . $expires ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token payload, not obfuscation.
		return $payload . '.' . hash_hmac( 'sha256', $purpose . '|' . $payload, wp_salt( 'auth' ) );
	}

	private static function verify( string $token, string $purpose ): string {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $purpose . '|' . $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
			return '';
		}
		$raw = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see sign().
		if ( false === $raw || ! str_contains( $raw, '|' ) ) {
			return '';
		}
		list( $email, $expires ) = explode( '|', $raw, 2 );
		return (int) $expires > time() && is_email( $email ) ? $email : '';
	}

	private static function cookie_email(): string {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return $raw ? self::verify( $raw, 'reader' ) : '';
	}

	private static function set_cookies( string $email ): void {
		$days    = max( 1, (int) Settings::get( 'paid_cookie_days' ) );
		$expires = time() + $days * DAY_IN_SECONDS;
		$secure  = is_ssl();
		setcookie( self::COOKIE, self::sign( $email, $expires, 'reader' ), array( 'expires' => $expires, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN, 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax' ) );
		setcookie( self::HINT_COOKIE, '1', array( 'expires' => $expires, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN, 'secure' => $secure, 'httponly' => false, 'samesite' => 'Lax' ) );
	}

	/** Lets a cached locked page know it is worth asking for the full text. */
	public static function hint_on_login( string $login, \WP_User $user ): void {
		if ( Members::rank_for( array( $user->user_email ) ) ) {
			setcookie( self::HINT_COOKIE, '1', array( 'expires' => time() + 30 * DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
		}
	}

	/* ---------- reading links ---------- */

	public static function request_link(): void {
		$email = isset( $_POST['mh_email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['mh_email'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public form on cached pages; rate limited, and the link only goes to the address's own inbox.
		$back  = isset( $_POST['mh_back'] ) ? esc_url_raw( wp_unslash( $_POST['mh_back'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$back  = wp_validate_redirect( $back, Shell::landing_url() );
		$trap  = ! empty( $_POST['mh_website'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- honeypot.

		if ( ! $trap && is_email( $email ) && self::allow( 'ip:' . self::ip(), 10 ) && self::allow( 'email:' . $email, 3 ) && Members::rank_for( array( $email ) ) ) {
			$url = add_query_arg( self::LINK_ARG, self::sign( $email, time() + self::LINK_TTL, 'link' ), $back );
			/* translators: %s: newsletter name */
			$subject = sprintf( __( 'Your reading link for %s', 'mailerhive' ), Settings::brand() );
			$body    = sprintf(
				/* translators: 1: newsletter name, 2: link, 3: minutes */
				__( "Here is your link to read %1\$s on this device:\n\n%2\$s\n\nThe link works once and expires in %3\$d minutes. If you did not ask for it, you can ignore this email.", 'mailerhive' ),
				Settings::brand(),
				$url,
				(int) ( self::LINK_TTL / MINUTE_IN_SECONDS )
			);
			wp_mail( $email, $subject, $body );
		}
		// Same answer either way, so the form never reveals who subscribes.
		wp_safe_redirect( add_query_arg( 'mh_link', 'sent', $back ) . '#mh-paywall' );
		exit;
	}

	private static function allow( string $key, int $max ): bool {
		$key   = 'mh_rl_' . md5( $key );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	private static function ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$via    = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		// Behind a private proxy, use the hop that proxy appended. Earlier hops come from the client and can be forged.
		if ( $remote && $via && ! filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			$hops = explode( ',', $via );
			$hop  = trim( (string) end( $hops ) );
			if ( filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				return $hop;
			}
		}
		return $remote;
	}

	/** ?mh_access=token: verify once, set the reader cookie, drop the token from the URL. */
	public static function consume_link(): void {
		if ( empty( $_GET[ self::LINK_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed token is the credential.
			return;
		}
		nocache_headers();
		$token = sanitize_text_field( wp_unslash( $_GET[ self::LINK_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$email = self::verify( $token, 'link' );
		if ( $email && self::spend_link( $token ) ) {
			self::set_cookies( $email );
		}
		wp_safe_redirect( remove_query_arg( array( self::LINK_ARG, 'mh_link' ) ) );
		exit;
	}

	/**
	 * Mark a reading link spent. INSERT IGNORE succeeds for exactly one request;
	 * add_option() would not, because it upserts.
	 */
	private static function spend_link( string $token ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::USED_PREFIX . md5( $token ), (string) time() ) );
	}

	/** A spent marker only matters while its link could still verify. */
	public static function purge_spent_links(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( self::USED_PREFIX ) . '%', time() - 2 * self::LINK_TTL ) );
	}

	public static function sign_out(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Sign-out has to be submitted from the account form.', 'mailerhive' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'mailerhive_reader_signout' );
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		foreach ( array( self::COOKIE, self::HINT_COOKIE ) as $name ) {
			setcookie( $name, '', array( 'expires' => time() - DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => self::COOKIE === $name, 'samesite' => 'Lax' ) );
		}
		wp_safe_redirect( wp_validate_redirect( $back, Shell::landing_url() ) );
		exit;
	}

	/* ---------- rendering ---------- */

	/** Members get the full issue; keep that response out of every shared cache. */
	public static function no_store_for_members(): void {
		if ( ! is_singular( Issues::TYPE ) ) {
			return;
		}
		$post = get_queried_object();
		if ( self::is_paid( $post ) && self::can_read( $post ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- shared page-cache convention.
			}
			nocache_headers();
		}
	}

	public static function issue_body( $body, \WP_Post $post ) {
		if ( ! self::is_paid( $post ) || self::can_read( $post ) ) {
			return $body;
		}
		return self::preview_html( $post ) . self::paywall( $post );
	}

	/** Everything before the More block, or the first few blocks; '' when neither applies. */
	public static function preview_raw( \WP_Post $post ): string {
		$content = (string) $post->post_content;
		if ( preg_match( '/<!--more(.*?)?-->/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return substr( $content, 0, $m[0][1] );
		}
		if ( ! has_blocks( $content ) ) {
			return '';
		}
		$keep = max( 1, (int) Settings::get( 'paid_preview_blocks' ) );
		$out  = '';
		$n    = 0;
		foreach ( parse_blocks( $content ) as $block ) {
			if ( empty( $block['blockName'] ) && '' === trim( (string) $block['innerHTML'] ) ) {
				continue;
			}
			$out .= serialize_block( $block );
			if ( ++$n >= $keep ) {
				break;
			}
		}
		return $out;
	}

	/** Plain preview text, taken from the raw issue before any content filter runs. */
	public static function preview_plain( \WP_Post $post ): string {
		return wp_strip_all_tags( self::preview_source( $post ) );
	}

	public static function preview_html( \WP_Post $post ): string {
		if ( self::$in_preview ) {
			return '';
		}
		self::$in_preview = true;
		try {
			return (string) apply_filters( 'the_content', self::preview_source( $post ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		} finally {
			self::$in_preview = false;
		}
	}

	/** Block or More preview when present; otherwise the first few raw HTML elements. */
	private static function preview_source( \WP_Post $post ): string {
		$raw = self::preview_raw( $post );
		if ( '' !== $raw ) {
			return $raw;
		}
		return self::classic_slice( (string) $post->post_content );
	}

	/** First few top-level elements of classic or imported HTML, before shortcodes run. */
	private static function classic_slice( string $html ): string {
		$keep = max( 1, (int) Settings::get( 'paid_preview_blocks' ) );
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
		if ( defined( 'LIBXML_NONET' ) ) {
			$flags |= LIBXML_NONET;
		}
		$doc->loadHTML( '<?xml encoding="utf-8"?><div id="mh-root">' . $html . '</div>', $flags );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$root = $doc->getElementById( 'mh-root' );
		$out  = '';
		$n    = 0;
		if ( $root ) {
			foreach ( iterator_to_array( $root->childNodes ) as $node ) {
				if ( XML_ELEMENT_NODE !== $node->nodeType || '' === trim( $node->textContent ) ) {
					continue;
				}
				$out .= $doc->saveHTML( $node );
				if ( ++$n >= $keep ) {
					break;
				}
			}
		}
		if ( '' !== $out ) {
			return $out;
		}
		return '<p>' . esc_html( wp_trim_words( wp_strip_all_tags( $html ), 80 ) ) . '</p>';
	}

	public static function paywall( \WP_Post $post ): string {
		$need    = self::required( $post );
		$rank    = Plans::rank( $need );
		$emails  = self::viewer_emails();
		$sent    = isset( $_GET['mh_link'] ) && 'sent' === $_GET['mh_link']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tiers   = Plans::tiers();
		$heading = 1 === count( $tiers ) || $rank <= 1
			/* translators: %s: tier name */
			? sprintf( __( 'This issue is for %s subscribers', 'mailerhive' ), Plans::name( $need ) )
			/* translators: %s: tier name */
			: sprintf( __( 'This issue is for %s subscribers and above', 'mailerhive' ), Plans::name( $need ) );

		ob_start();
		?>
<div class="mh-paywall" id="mh-paywall" data-mh-issue="<?php echo (int) $post->ID; ?>">
	<section class="mh-paywall__card" aria-labelledby="mh-paywall-h">
		<span class="mh-sub__icon"><?php echo Icons::get( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<h2 id="mh-paywall-h"><?php echo esc_html( $heading ); ?></h2>
		<?php if ( Settings::get( 'paid_plans_intro' ) ) : ?><p class="mh-paywall__intro"><?php echo esc_html( (string) Settings::get( 'paid_plans_intro' ) ); ?></p><?php endif; ?>
		<?php if ( $emails && ! $sent ) : ?>
			<p class="mh-paywall__note"><?php echo esc_html( sprintf( /* translators: %s: email address */ __( 'You are signed in as %s, which has no active subscription at this level.', 'mailerhive' ), $emails[0] ) ); ?></p>
		<?php endif; ?>
		<?php echo self::plans_html( $rank ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in plans_html(). ?>
		<?php echo self::link_form( get_permalink( $post ), $sent ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in link_form(). ?>
	</section>
</div>
<script>
!function(){var w=document.getElementById('mh-paywall');if(!w||document.cookie.indexOf('<?php echo esc_js( self::HINT_COOKIE ); ?>=')<0)return;var f=new FormData();f.append('action','mailerhive_unlock');f.append('post',w.getAttribute('data-mh-issue'));fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,{method:'POST',body:f,credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json()}).then(function(r){if(!r||!r.success)return;var b=w.parentNode;b.innerHTML=r.data.html;}).catch(function(){})}();
</script>
		<?php
		return (string) ob_get_clean();
	}

	private static function link_form( string $back, bool $sent ): string {
		$user = wp_get_current_user();
		ob_start();
		?>
<details class="mh-paywall__login"<?php echo $sent ? ' open' : ''; ?>>
	<summary><?php esc_html_e( 'Already a paid subscriber?', 'mailerhive' ); ?></summary>
	<?php if ( $sent ) : ?>
		<p class="mh-paywall__sent" role="status"><?php echo Icons::get( 'mail-opened' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'If that email has an active subscription, a reading link is on its way. It works once and expires in an hour.', 'mailerhive' ); ?></p>
	<?php endif; ?>
	<form class="mh-paywall__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="mailerhive_reader_link">
		<input type="hidden" name="mh_back" value="<?php echo esc_url( $back ); ?>">
		<p class="mh-hp" aria-hidden="true"><label>Website <input type="text" name="mh_website" tabindex="-1" autocomplete="off"></label></p>
		<label class="screen-reader-text mh-sr" for="mh-reader-email"><?php esc_html_e( 'Email you subscribed with', 'mailerhive' ); ?></label>
		<input id="mh-reader-email" type="email" name="mh_email" required autocomplete="email" placeholder="<?php esc_attr_e( 'Email you subscribed with', 'mailerhive' ); ?>">
		<button type="submit" class="mh-btn mh-btn--ghost"><?php esc_html_e( 'Email me a reading link', 'mailerhive' ); ?></button>
	</form>
	<?php if ( ! $user->exists() ) : ?>
		<p class="mh-paywall__alt"><a href="<?php echo esc_url( wp_login_url( $back ) ); ?>"><?php esc_html_e( 'Or sign in to your account', 'mailerhive' ); ?></a></p>
	<?php endif; ?>
</details>
		<?php
		return (string) ob_get_clean();
	}

	/** Tier cards with their plans, from $min_rank up (1-based; 0 shows all). */
	public static function plans_html( int $min_rank = 0, string $heading = '' ): string {
		$tiers = Plans::tiers();
		$cards = '';
		if ( $min_rank < 1 && Settings::on( 'paid_show_free' ) ) {
			$desc   = (string) Settings::get( 'free_tier_description' ) ?: Settings::tagline();
			$target = Shell::context() === 'landing' ? '#mh-subscribe' : Shell::landing_url() . '#mh-subscribe';
			$cards .= '<div class="mh-tier mh-tier--free"><h3 class="mh-tier__name">' . esc_html( (string) Settings::get( 'free_tier_name' ) ?: __( 'Free', 'mailerhive' ) ) . '</h3>'
				. ( $desc ? '<p class="mh-tier__desc">' . esc_html( $desc ) . '</p>' : '' )
				. sprintf(
					'<ul class="mh-tier__plans" role="list"><li><a class="mh-plan" href="%1$s"><span class="mh-plan__name">%2$s</span><span class="mh-plan__price"><b>%3$s</b> <small>%4$s</small></span><span class="mh-plan__go">%5$s</span></a></li></ul></div>',
					esc_url( $target ),
					esc_html__( 'Subscribe by email', 'mailerhive' ),
					esc_html__( 'Free', 'mailerhive' ),
					esc_html__( 'unsubscribe anytime', 'mailerhive' ),
					Icons::get( 'mail' )
				);
		}
		$free_only = $cards;
		foreach ( $tiers as $i => $tier ) {
			if ( $i + 1 < $min_rank ) {
				continue;
			}
			$buttons = '';
			foreach ( (array) $tier['plans'] as $plan ) {
				$var = Plans::variation( (int) $plan['variation_id'] );
				if ( ! $var || 'publish' !== $var['status'] ) {
					continue;
				}
				$label    = '' !== (string) ( $plan['label'] ?? '' ) ? (string) $plan['label'] : $var['title'];
				$buttons .= sprintf(
					'<li><a class="mh-plan" href="%1$s"><span class="mh-plan__name">%2$s</span><span class="mh-plan__price"><b data-mh-price="%6$d">%3$s</b> <small>%4$s</small></span><span class="mh-plan__go">%5$s</span></a></li>',
					esc_url( Plans::checkout_url( (int) $var['id'] ) ),
					esc_html( $label ),
					esc_html( Plans::price( (int) $var['price'] ) ),
					esc_html( Plans::terms_label( $plan, $var ) ),
					Icons::get( 'arrow-right' ),
					(int) $var['id']
				);
			}
			if ( '' === $buttons ) {
				continue;
			}
			$cards .= '<div class="mh-tier"><h3 class="mh-tier__name">' . esc_html( $tier['name'] ) . '</h3>'
				. ( $tier['description'] ? '<p class="mh-tier__desc">' . nl2br( esc_html( $tier['description'] ) ) . '</p>' : '' )
				. '<ul class="mh-tier__plans" role="list">' . $buttons . '</ul></div>';
		}
		if ( '' === $cards || $cards === $free_only ) {
			return '';
		}
		return ( $heading ? '<h2 class="mh-plans__h">' . esc_html( $heading ) . '</h2>' : '' ) . '<div class="mh-tiers">' . $cards . '</div>' . self::currency_script();
	}

	/**
	 * Plans render in the store currency so cached pages are identical for everyone.
	 * With GT Extensions' multi-currency on, the page then asks admin-ajax (never
	 * cached) for this visitor's prices and swaps them in, matching checkout.
	 */
	private static function currency_script(): string {
		static $done = false;
		if ( $done || ! Plans::multi_currency() ) {
			return '';
		}
		$done = true;
		return '<script>!function(){var els=document.querySelectorAll("[data-mh-price]");if(!els.length)return;var ids=[];els.forEach(function(e){ids.push(e.getAttribute("data-mh-price"))});var f=new FormData();f.append("action","mailerhive_prices");f.append("ids",ids.join(","));fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",body:f,credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json()}).then(function(r){if(!r||!r.success||!r.data.prices)return;document.querySelectorAll("[data-mh-price]").forEach(function(e){var p=r.data.prices[e.getAttribute("data-mh-price")];if(p){e.textContent=p}})}).catch(function(){})}();</script>';
	}

	/** admin-ajax: this visitor's prices for the given variations, in their currency. */
	public static function ajax_prices(): void {
		nocache_headers();
		$module = Plans::multi_currency();
		if ( ! $module ) {
			wp_send_json_error( null, 404 );
		}
		$currency = (string) $module->getCurrentCurrency();
		$mapped   = Plans::by_variation();
		$ids      = array_slice( array_unique( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ?? '' ) ) ) ) ), 0, 50 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public, read-only prices.
		$prices   = array();
		foreach ( $ids as $id ) {
			if ( isset( $mapped[ $id ] ) ) {
				$price = Plans::price_in( $id, $currency );
				if ( '' !== $price ) {
					$prices[ $id ] = $price;
				}
			}
		}
		wp_send_json_success( array( 'currency' => $currency, 'prices' => $prices ) );
	}

	public static function landing_plans(): void {
		if ( ! Settings::on( 'paid_show_on_landing' ) ) {
			return;
		}
		$html = self::plans_html( 0 );
		if ( '' === $html ) {
			return;
		}
		echo '<section class="mh-mail mh-plans" id="mh-plans" aria-labelledby="mh-plans-h"><div class="mh-plans__in"><span class="mh-sub__icon">' . Icons::get( 'lock-open' ) . '</span><h2 id="mh-plans-h">' . esc_html( (string) Settings::get( 'paid_plans_heading' ) ?: __( 'Become a paid subscriber', 'mailerhive' ) ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon markup is static.
		if ( Settings::get( 'paid_plans_intro' ) ) {
			echo '<p class="mh-paywall__intro">' . esc_html( (string) Settings::get( 'paid_plans_intro' ) ) . '</p>';
		}
		echo $html . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in plans_html().
	}

	public static function plans_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'tier' => '', 'heading' => '' ), $atts, 'mailerhive_plans' );
		$html = self::plans_html( $atts['tier'] ? Plans::rank( sanitize_key( $atts['tier'] ) ) : 0, (string) $atts['heading'] );
		return $html ? '<div class="mh-plans-embed">' . $html . '</div>' : '';
	}

	/** [mailerhive_account]: the visitor's paid access, or the reading-link form. */
	public static function account_shortcode(): string {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- shared page-cache convention.
		}
		nocache_headers();
		$emails = self::viewer_emails();
		$back   = (string) get_permalink();
		if ( ! $emails ) {
			return '<div class="mh-account">' . self::link_form( $back, isset( $_GET['mh_link'] ) ) . '</div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$rows = array();
		foreach ( $emails as $email ) {
			$rows = array_merge( $rows, Members::rows_for( $email ) );
		}
		$out = '<div class="mh-account"><p>' . esc_html( sprintf( /* translators: %s: email */ __( 'Signed in as %s.', 'mailerhive' ), implode( ', ', $emails ) ) ) . '</p>';
		if ( ! $rows ) {
			$out .= '<p>' . esc_html__( 'There is no paid subscription on this email yet.', 'mailerhive' ) . '</p>';
		} else {
			$out .= '<ul class="mh-account__list" role="list">';
			foreach ( $rows as $row ) {
				$out .= '<li><b>' . esc_html( Plans::name( (string) $row->tier ) ) . '</b> · ' . esc_html( self::status_label( $row ) ) . '</li>';
			}
			$out .= '</ul>';
		}
		$portal = '';
		if ( class_exists( '\FluentCart\Api\StoreSettings' ) ) {
			try {
				$portal = (string) ( new \FluentCart\Api\StoreSettings() )->getCustomerProfilePage();
			} catch ( \Throwable $e ) {
				$portal = '';
			}
		}
		if ( $portal ) {
			$out .= '<p><a class="mh-btn mh-btn--ghost" href="' . esc_url( $portal ) . '">' . esc_html__( 'Manage billing and renewals', 'mailerhive' ) . '</a></p>';
		}
		if ( self::cookie_email() ) {
			$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$out .= '<input type="hidden" name="action" value="mailerhive_reader_signout" />';
			$out .= '<input type="hidden" name="back" value="' . esc_attr( $back ) . '" />';
			$out .= wp_nonce_field( 'mailerhive_reader_signout', '_wpnonce', true, false );
			$out .= '<p><button type="submit">' . esc_html__( 'Forget this device', 'mailerhive' ) . '</button></p></form>';
		}
		return $out . '</div>';
	}

	public static function status_label( object $row ): string {
		$date = static fn( $d ) => $d ? wp_date( get_option( 'date_format' ), strtotime( $d . ' UTC' ) ) : '';
		$live = Members::is_live( $row );
		if ( $live && null === $row->expires_at ) {
			return __( 'Active, no end date', 'mailerhive' );
		}
		if ( $live && 'subscription' === $row->mode && 'active' === $row->status ) {
			/* translators: %s: date */
			return sprintf( __( 'Active, renews around %s', 'mailerhive' ), $date( $row->expires_at ) );
		}
		if ( $live ) {
			/* translators: %s: date */
			return sprintf( __( 'Active until %s', 'mailerhive' ), $date( $row->expires_at ) );
		}
		$labels = array(
			'paused'   => __( 'Paused', 'mailerhive' ),
			'refunded' => __( 'Refunded', 'mailerhive' ),
			'revoked'  => __( 'Revoked', 'mailerhive' ),
		);
		/* translators: %s: date */
		return $labels[ $row->status ] ?? sprintf( __( 'Ended %s', 'mailerhive' ), $date( $row->expires_at ) );
	}

	public static function badge( $html, \WP_Post $post ) {
		$need = self::required( $post );
		if ( '' === $need ) {
			return $html;
		}
		return $html . '<span class="mh-badge">' . Icons::get( 'lock' ) . '<span>' . esc_html( Plans::name( $need ) ) . '</span></span>';
	}

	/* ---------- other channels ---------- */

	public static function ajax_unlock(): void {
		nocache_headers();
		$post = get_post( absint( $_POST['post'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only; the reader's own session decides the answer.
		if ( ! $post || Issues::TYPE !== $post->post_type || 'publish' !== $post->post_status || ! self::is_paid( $post ) || ! self::can_read( $post ) ) {
			wp_send_json_error( null, 403 );
		}
		remove_filter( 'mailerhive_issue_body', array( __CLASS__, 'issue_body' ), 10 );
		$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		wp_send_json_success( array( 'html' => $html ) );
	}

	public static function feed_content( $content ) {
		$post = get_post();
		if ( ! $post || ! self::is_paid( $post ) ) {
			return $content;
		}
		return self::preview_html( $post ) . '<p><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( sprintf( /* translators: %s: tier name */ __( 'Continue reading (for %s subscribers)', 'mailerhive' ), Plans::name( self::required( $post ) ) ) ) . '</a></p>';
	}

	/** Search, archives, and other secondary views. The issue page uses mailerhive_issue_body. */
	public static function guard_secondary_content( string $content ): string {
		if ( self::$in_preview || is_admin() || is_singular( Issues::TYPE ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || Issues::TYPE !== $post->post_type || ! self::is_paid( $post ) || self::can_read( $post ) ) {
			return $content;
		}
		return self::preview_html( $post );
	}

	/**
	 * Runs before WordPress fills an empty excerpt from the full post.
	 * A non-empty return keeps shortcodes in the paid part from running.
	 */
	public static function guard_excerpt_early( $excerpt, $post ) {
		if ( self::$in_preview || ! $post instanceof \WP_Post || ! self::excerpt_locked( $post ) || '' !== trim( (string) $excerpt ) ) {
			return $excerpt;
		}
		$text = self::preview_plain( $post );
		return '' === trim( $text ) ? __( 'Subscribers only', 'mailerhive' ) : wp_trim_words( $text, 55 );
	}

	public static function guard_excerpt( $excerpt, $post ): string {
		$excerpt = (string) $excerpt;
		if ( self::$in_preview || ! $post instanceof \WP_Post || ! self::excerpt_locked( $post ) ) {
			return $excerpt;
		}
		$text = self::preview_plain( $post );
		return '' === $text ? '' : wp_trim_words( $text, 55 );
	}

	private static function excerpt_locked( \WP_Post $post ): bool {
		return Issues::TYPE === $post->post_type && self::is_paid( $post ) && ! current_user_can( 'edit_post', $post->ID ) && ! self::can_read( $post );
	}

	public static function rest_content( \WP_REST_Response $response, \WP_Post $post ): \WP_REST_Response {
		if ( ! self::is_paid( $post ) || current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$data['content']['rendered']  = self::preview_html( $post );
			$data['content']['protected'] = true;
			unset( $data['content']['raw'] );
		}
		if ( isset( $data['excerpt'] ) && is_array( $data['excerpt'] ) ) {
			$data['excerpt']['rendered'] = wpautop( esc_html( wp_trim_words( self::preview_plain( $post ), 55 ) ) );
			unset( $data['excerpt']['raw'] );
		}
		$response->set_data( $data );
		return $response;
	}

	/** Tell search engines the full text is behind a subscription. */
	public static function schema(): void {
		if ( ! is_singular( Issues::TYPE ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! self::is_paid( $post ) ) {
			return;
		}
		$data = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Article',
			'headline'            => wp_strip_all_tags( get_the_title( $post ) ),
			'url'                 => get_permalink( $post ),
			'datePublished'       => get_the_date( 'c', $post ),
			'isAccessibleForFree' => false,
			'isPartOf'            => array(
				'@type'               => array( 'CreativeWork', 'Product' ),
				'name'                => Settings::brand(),
				'productID'           => wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . self::required( $post ),
			),
		);
		wp_print_inline_script_tag( wp_json_encode( $data ), array( 'type' => 'application/ld+json' ) );
	}
}
