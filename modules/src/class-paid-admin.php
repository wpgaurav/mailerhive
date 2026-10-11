<?php
/**
 * Paid subscriptions in wp-admin: the Paid settings tab (tiers and plans), the
 * "Who can read this issue" control, the Access column, and the Paid members screen
 * with manual grants.
 */

namespace MailerHive\Modules\Paid;

use MailerHive\Issues;
use MailerHive\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- MailerHive's own members table; name from $wpdb->prefix, values prepared.

final class Admin {
	const PAGE = 'mailerhive-members';

	public static function boot(): void {
		add_action( 'mailerhive_issue_meta_box', array( __CLASS__, 'issue_field' ) );
		add_action( 'mailerhive_issue_meta_save', array( __CLASS__, 'save_issue_field' ) );
		add_filter( 'manage_' . Issues::TYPE . '_posts_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_' . Issues::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_mailerhive_members', array( __CLASS__, 'handle' ) );
	}

	/* ---------- issue editor ---------- */

	public static function issue_field( \WP_Post $post ): void {
		$tiers = Plans::tiers();
		if ( ! $tiers ) {
			printf( '<p class="description">%s</p>', esc_html__( 'Add a paid tier in Newsletter → Settings → Paid to make issues paid-only.', 'mailerhive' ) );
			return;
		}
		$current = (string) get_post_meta( $post->ID, Access::META, true );
		echo '<p><label for="mh-access"><strong>' . esc_html__( 'Who can read this issue', 'mailerhive' ) . '</strong></label><select id="mh-access" name="mh_access" style="width:100%">';
		/* translators: %s: free tier name */
		printf( '<option value="">%s</option>', esc_html( sprintf( __( 'Everyone (%s)', 'mailerhive' ), (string) Settings::get( 'free_tier_name' ) ?: __( 'Free', 'mailerhive' ) ) ) );
		foreach ( $tiers as $i => $tier ) {
			$label = $i < count( $tiers ) - 1
				/* translators: %s: tier name */
				? sprintf( __( '%s and above', 'mailerhive' ), $tier['name'] )
				: $tier['name'];
			printf( '<option value="%s"%s>%s</option>', esc_attr( $tier['key'] ), selected( $current, $tier['key'], false ), esc_html( $label ) );
		}
		echo '</select></p><p class="description">' . esc_html__( 'Paid issues show a preview up to the More block (or the first few blocks) and then the plans. Drafts go to the matching paid lists.', 'mailerhive' ) . '</p>';
	}

	public static function save_issue_field( int $post_id ): void {
		// Nonce checked by Issues::save_meta_box() before this action fires.
		$key = isset( $_POST['mh_access'] ) ? sanitize_key( wp_unslash( $_POST['mh_access'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( null === $key ) {
			return;
		}
		if ( '' !== $key && Plans::rank( $key ) ) {
			update_post_meta( $post_id, Access::META, $key );
		} else {
			delete_post_meta( $post_id, Access::META );
		}
	}

	public static function columns( array $cols ): array {
		$cols['mh_access'] = __( 'Access', 'mailerhive' );
		return $cols;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'mh_access' === $col ) {
			$need = Access::required( $post_id );
			echo esc_html( $need ? Plans::name( $need ) : __( 'Free', 'mailerhive' ) );
		}
	}

	/* ---------- settings tab ---------- */

	public static function tab( array $s ): void {
		$name     = static fn( string $k ): string => Settings::OPTION . '[' . $k . ']';
		$tiers    = Plans::tiers();
		$lists    = Settings::mailerpress_lists();
		$vars     = Plans::variations();
		$counts   = Members::counts();
		$fc_ready = Plans::fluentcart_ready();

		echo '<tr><td colspan="2" class="mh-paid-cell"><div class="mh-paid">';
		self::styles();
		echo '<p class="mh-paid__intro">' . esc_html__( 'Readers start on Free and move up. Each paid tier unlocks its own issues and every issue of the tiers below it, and has its own MailerPress list that MailerHive keeps in sync with FluentCart.', 'mailerhive' ) . '</p>';

		echo '<ol class="mh-ladder" aria-label="' . esc_attr__( 'Tier order', 'mailerhive' ) . '"><li class="mh-ladder__step mh-ladder__step--free"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span>' . esc_html( (string) $s['free_tier_name'] ?: __( 'Free', 'mailerhive' ) ) . '</li>';
		foreach ( $tiers as $tier ) {
			$n = (int) ( $counts[ $tier['key'] ] ?? 0 );
			/* translators: %d: active readers */
			echo '<li class="mh-ladder__arrow" aria-hidden="true">&rarr;</li><li class="mh-ladder__step"><span class="dashicons dashicons-lock" aria-hidden="true"></span>' . esc_html( $tier['name'] ) . ' <span class="mh-ladder__count">' . esc_html( sprintf( _n( '%d reader', '%d readers', $n, 'mailerhive' ), $n ) ) . '</span></li>';
		}
		echo '</ol>';

		if ( ! $fc_ready ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'FluentCart is not active. Tiers can be set up, but plans need FluentCart products.', 'mailerhive' ) . '</p></div>';
		}
		self::free_box( $s );
		echo '<div id="mh-tiers">';
		foreach ( $tiers as $t => $tier ) {
			self::tier_box( (string) $t, $tier, $lists, $vars, $counts[ $tier['key'] ] ?? 0 );
		}
		echo '</div><p><button type="button" class="button mh-add-tier-btn" id="mh-add-tier"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__( 'Add a higher tier', 'mailerhive' ) . '</button></p>';
		echo '<template id="mh-tier-tpl">';
		self::tier_box( '__T__', array( 'key' => '', 'name' => '', 'description' => '', 'list_id' => -1, 'product_id' => 0, 'plans' => array() ), $lists, $vars, 0 );
		echo '</template><template id="mh-plan-tpl">';
		self::plan_row( '__T__', '__P__', array(), $vars );
		echo '</template>';
		echo '</div></td></tr>';

		self::heading_row( __( 'Reading and delivery', 'mailerhive' ) );
		self::number_row( $s, 'paid_preview_blocks', __( 'Preview length', 'mailerhive' ), __( 'Blocks shown before the paywall when the issue has no More block.', 'mailerhive' ) );
		self::number_row( $s, 'paid_grace_days', __( 'Grace period (days)', 'mailerhive' ), __( 'How long a subscription keeps access after a missed renewal date while FluentCart retries the payment.', 'mailerhive' ) );
		self::number_row( $s, 'paid_cookie_days', __( 'Remember readers for (days)', 'mailerhive' ), __( 'How long a reading link keeps a browser signed in. Access still ends when the subscription does.', 'mailerhive' ) );
		self::check_row( $s, 'paid_resubscribe', __( 'Purchases', 'mailerhive' ), __( 'Re-subscribe a contact who had unsubscribed when they buy a paid plan', 'mailerhive' ), __( 'Buying the paid newsletter is a direct request to receive it. Bounced and complaint contacts are never changed. The purchase is saved as consent evidence on the contact.', 'mailerhive' ) );
		self::check_row( $s, 'paid_add_free_list', __( 'Free issues', 'mailerhive' ), __( 'Also add paying readers to the free newsletter lists', 'mailerhive' ), __( 'So they keep getting free issues. They stay on those lists after a paid plan ends.', 'mailerhive' ) );

		self::heading_row( __( 'Plans on the site', 'mailerhive' ) );
		self::check_row( $s, 'paid_show_on_landing', __( 'Newsletter page', 'mailerhive' ), __( 'Show the plans on the newsletter page, under the signup card', 'mailerhive' ) );
		printf( '<tr><th scope="row"><label for="mh-paid_plans_heading">%s</label></th><td><input type="text" class="regular-text" id="mh-paid_plans_heading" name="%s" value="%s" placeholder="%s"></td></tr>', esc_html__( 'Plans heading', 'mailerhive' ), esc_attr( $name( 'paid_plans_heading' ) ), esc_attr( (string) $s['paid_plans_heading'] ), esc_attr__( 'Become a paid subscriber', 'mailerhive' ) );
		printf( '<tr><th scope="row"><label for="mh-paid_plans_intro">%s</label></th><td><textarea class="large-text" rows="2" id="mh-paid_plans_intro" name="%s">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Plans intro', 'mailerhive' ), esc_attr( $name( 'paid_plans_intro' ) ), esc_textarea( (string) $s['paid_plans_intro'] ), esc_html__( 'One or two sentences on what paying readers get. Shown on the paywall and with the plans.', 'mailerhive' ) );
		printf( '<tr><th scope="row">%s</th><td><code>[mailerhive_plans]</code> <code>[mailerhive_plans tier="paid"]</code> <code>[mailerhive_account]</code><p class="description">%s</p></td></tr>', esc_html__( 'Shortcodes', 'mailerhive' ), esc_html__( 'Plans anywhere on the site, and an account box that shows a reader their access or emails them a reading link.', 'mailerhive' ) );
		self::script();
	}

	private static function tier_box( string $t, array $tier, array $lists, array $vars, int $count ): void {
		$n   = static fn( string $k ): string => Settings::OPTION . '[paid_tiers][' . $t . '][' . $k . ']';
		$id  = 'mh-t-' . $t;
		$new = '' === (string) $tier['key'];
		echo '<section class="mh-card mh-tier-box" aria-label="' . esc_attr( $tier['name'] ?: __( 'New tier', 'mailerhive' ) ) . '">';
		printf( '<input type="hidden" name="%s" value="%d">', esc_attr( $n( 'product_id' ) ), (int) ( $tier['product_id'] ?? 0 ) );
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $n( 'key' ) ), esc_attr( (string) $tier['key'] ) );

		echo '<header class="mh-card__head"><span class="mh-card__badge" aria-hidden="true"><span class="dashicons dashicons-lock"></span></span><div class="mh-card__title">';
		printf( '<label class="screen-reader-text" for="%1$s-name">%2$s</label><input type="text" id="%1$s-name" class="mh-card__name" name="%3$s" value="%4$s" placeholder="%5$s" required>', esc_attr( $id ), esc_html__( 'Tier name', 'mailerhive' ), esc_attr( $n( 'name' ) ), esc_attr( (string) $tier['name'] ), esc_attr__( 'Tier name, e.g. Insider', 'mailerhive' ) );
		echo '<div class="mh-card__meta">';
		if ( ! $new ) {
			/* translators: %s: tier key */
			echo '<span class="mh-chip" title="' . esc_attr__( 'Issues store this key, so it never changes.', 'mailerhive' ) . '">' . esc_html( sprintf( __( 'key: %s', 'mailerhive' ), $tier['key'] ) ) . '</span>';
			/* translators: %d: active readers */
			echo '<span class="mh-chip' . ( $count ? ' mh-chip--ok' : '' ) . '">' . esc_html( sprintf( _n( '%d active reader', '%d active readers', $count, 'mailerhive' ), $count ) ) . '</span>';
		} else {
			echo '<span class="mh-chip">' . esc_html__( 'New tier', 'mailerhive' ) . '</span>';
		}
		echo '</div></div>';
		echo '<button type="button" class="mh-icon-btn mh-remove-tier" aria-label="' . esc_attr__( 'Remove tier', 'mailerhive' ) . '" title="' . esc_attr__( 'Remove tier', 'mailerhive' ) . '"><span class="dashicons dashicons-trash"></span></button></header>';

		echo '<div class="mh-card__body"><div class="mh-grid">';
		printf( '<div class="mh-field"><label for="%1$s-desc">%2$s</label><textarea id="%1$s-desc" rows="3" name="%3$s" placeholder="%4$s">%5$s</textarea><p class="mh-help">%6$s</p></div>', esc_attr( $id ), esc_html__( 'What readers get', 'mailerhive' ), esc_attr( $n( 'description' ) ), esc_attr__( 'Every paid issue, the full archive, and…', 'mailerhive' ), esc_textarea( (string) $tier['description'] ), esc_html__( 'Shown on the tier’s card in the plans and on the FluentCart product.', 'mailerhive' ) );
		$select = sprintf( '<select id="%s-list" name="%s"><option value="0">%s</option><option value="-1"%s>%s</option>', esc_attr( $id ), esc_attr( $n( 'list_id' ) ), esc_html__( '— No list —', 'mailerhive' ), selected( (int) $tier['list_id'], -1, false ), esc_html__( '+ Create a list for this tier', 'mailerhive' ) );
		foreach ( $lists as $lid => $label ) {
			$select .= sprintf( '<option value="%d"%s>%s</option>', (int) $lid, selected( (int) $tier['list_id'], (int) $lid, false ), esc_html( $label ) );
		}
		printf( '<div class="mh-field"><label for="%s-list">%s</label>%s</select><p class="mh-help">%s</p></div>', esc_attr( $id ), esc_html__( 'MailerPress list', 'mailerhive' ), $select, esc_html__( 'Paying readers are added here while their access lasts and removed when it ends. Drafts for this tier’s issues go to this list and the lists of higher tiers.', 'mailerhive' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div>';

		self::product_section( $t, $tier );

		echo '<div><h4 class="mh-section-title">' . esc_html__( 'Plans', 'mailerhive' ) . '</h4><div class="mh-plans-list"><div class="mh-plan-row mh-plan-row--head" aria-hidden="true"><span>' . esc_html__( 'FluentCart price', 'mailerhive' ) . '</span><span>' . esc_html__( 'Access', 'mailerhive' ) . '</span><span>' . esc_html__( 'Button label', 'mailerhive' ) . '</span><span></span></div>';
		echo '<div class="mh-plan-rows" data-empty="' . esc_attr__( 'No plans yet. Create a FluentCart product above, or add a price from an existing product.', 'mailerhive' ) . '">';
		foreach ( (array) $tier['plans'] as $p => $plan ) {
			self::plan_row( $t, (string) $p, (array) $plan, $vars );
		}
		echo '</div></div><p class="mh-plans-add"><button type="button" class="button-link mh-add-plan" data-tier="' . esc_attr( $t ) . '"><span class="dashicons dashicons-plus" aria-hidden="true"></span>' . esc_html__( 'Add a price from an existing product', 'mailerhive' ) . '</button></p></div>';
		echo '</div></section>';
	}

	private static function free_box( array $s ): void {
		$n = static fn( string $k ): string => Settings::OPTION . '[' . $k . ']';
		echo '<section class="mh-card mh-card--free" aria-label="' . esc_attr__( 'Free tier', 'mailerhive' ) . '">';
		echo '<header class="mh-card__head"><span class="mh-card__badge" aria-hidden="true"><span class="dashicons dashicons-email-alt"></span></span><div class="mh-card__title">';
		printf( '<label class="screen-reader-text" for="mh-free-name">%s</label><input type="text" id="mh-free-name" class="mh-card__name" name="%s" value="%s">', esc_html__( 'Free tier name', 'mailerhive' ), esc_attr( $n( 'free_tier_name' ) ), esc_attr( (string) $s['free_tier_name'] ) );
		echo '<div class="mh-card__meta"><span class="mh-chip mh-chip--ok">' . esc_html__( 'Always first, no charge', 'mailerhive' ) . '</span></div></div></header>';
		echo '<div class="mh-card__body"><div class="mh-grid">';
		printf( '<div class="mh-field"><label for="mh-free-desc">%s</label><input type="text" id="mh-free-desc" name="%s" value="%s" placeholder="%s"><p class="mh-help">%s</p></div>', esc_html__( 'What free readers get', 'mailerhive' ), esc_attr( $n( 'free_tier_description' ) ), esc_attr( (string) $s['free_tier_description'] ), esc_attr( Settings::tagline() ), esc_html__( 'Defaults to the tagline.', 'mailerhive' ) );
		/* translators: %s: link to the MailerPress tab */
		printf( '<div class="mh-field"><span class="mh-field__label">%s</span><p class="mh-help mh-help--flush">%s</p></div>', esc_html__( 'Delivery', 'mailerhive' ), wp_kses_post( sprintf( __( 'Free issues go to the lists on the <a href="%s">MailerPress tab</a>, and readers join through the signup form on the General tab.', 'mailerhive' ), esc_url( add_query_arg( 'tab', 'mailerpress', Settings::url() ) ) ) ) );
		echo '</div>';
		printf( '<label class="mh-toggle"><input type="checkbox" name="%s" value="1"%s> %s</label>', esc_attr( $n( 'paid_show_free' ) ), checked( ! empty( $s['paid_show_free'] ), true, false ), esc_html__( 'Show a Free card next to the paid plans', 'mailerhive' ) );
		echo '</div></section>';
	}

	private static function product_section( string $t, array $tier ): void {
		$product = (int) ( $tier['product_id'] ?? 0 );
		$post    = $product ? get_post( $product ) : null;
		if ( ! Products::ready() ) {
			echo '<div class="mh-product"><p class="mh-help mh-help--flush">' . esc_html__( 'Activate FluentCart to create a product for this tier in one click.', 'mailerhive' ) . '</p></div>';
			return;
		}
		$form = static function ( bool $primary ) use ( $t, $product ) {
			$sign     = Plans::currency_sign();
			$defaults = $product ? array( 'monthly' => '', 'yearly' => '', 'lifetime' => '' ) : Products::defaults();
			$suffix   = array( 'monthly' => __( '/ month', 'mailerhive' ), 'yearly' => __( '/ year', 'mailerhive' ), 'lifetime' => __( 'once', 'mailerhive' ) );
			echo '<div class="mh-prices">';
			foreach ( Products::labels() as $key => $label ) {
				$fid = 'mh-price-' . $t . '-' . $key;
				printf(
					'<div class="mh-field mh-field--price"><label for="%1$s">%2$s</label><div class="mh-money"><span>%3$s</span><input type="text" inputmode="decimal" id="%1$s" name="mh_new_price[%4$s][%5$s]" value="%6$s" placeholder="—"><span>%7$s</span></div></div>',
					esc_attr( $fid ),
					esc_html( $label ),
					esc_html( $sign ),
					esc_attr( $t ),
					esc_attr( $key ),
					esc_attr( $defaults[ $key ] ),
					esc_html( $suffix[ $key ] )
				);
			}
			printf(
				'<button type="submit" class="button %s mh-create" name="%s" value="%s"><span class="dashicons dashicons-cart" aria-hidden="true"></span>%s</button>',
				$primary ? 'button-primary' : 'button-secondary',
				esc_attr( Settings::OPTION . '[_create_product]' ),
				esc_attr( $t ),
				esc_html( $primary ? __( 'Create FluentCart product', 'mailerhive' ) : __( 'Create product', 'mailerhive' ) )
			);
			echo '</div><p class="mh-help">' . esc_html__( 'Leave a price empty to skip it. Saves your settings too.', 'mailerhive' ) . '</p>';
		};

		if ( $post ) {
			$status = 'publish' === $post->post_status ? __( 'Published', 'mailerhive' ) : ucfirst( (string) $post->post_status );
			echo '<div class="mh-product mh-product--linked"><span class="mh-product__icon dashicons dashicons-cart" aria-hidden="true"></span><div class="mh-product__main"><span class="mh-product__label">' . esc_html__( 'FluentCart product', 'mailerhive' ) . '</span><strong>' . esc_html( get_the_title( $post ) ) . '</strong></div>';
			echo '<span class="mh-chip' . ( 'publish' === $post->post_status ? ' mh-chip--ok' : '' ) . '">' . esc_html( $status ) . '</span>';
			echo '<a class="button" href="' . esc_url( Products::edit_url( $product ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Edit in FluentCart', 'mailerhive' ) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a></div>';
			echo '<details class="mh-product-more"><summary>' . esc_html__( 'Create another product with different prices', 'mailerhive' ) . '</summary>';
			$form( false );
			echo '</details>';
			return;
		}
		echo '<div class="mh-product"><h4 class="mh-product__title"><span class="dashicons dashicons-cart" aria-hidden="true"></span>' . esc_html__( 'Sell this tier', 'mailerhive' ) . '</h4><p class="mh-help mh-help--flush">' . esc_html__( 'One click creates a published FluentCart product with these prices and adds them to the plans below.', 'mailerhive' ) . '</p>';
		$form( true );
		echo '</div>';
	}

	/**
	 * Handle the "Create FluentCart product" button during the settings save.
	 *
	 * @param string $posted_index Form index of the tier whose button was pressed.
	 * @param array  $posted       Raw posted tiers (form order).
	 * @param array  $tiers        Sanitized tiers (same order, nameless tiers dropped).
	 */
	private static function create_product_for( string $posted_index, array $posted, array $tiers ): array {
		static $done = false; // WordPress can run the sanitize callback twice on one save.
		if ( $done ) {
			return $tiers;
		}
		$done = true;
		$pos  = -1;
		$i    = 0;
		foreach ( $posted as $index => $row ) {
			if ( '' === sanitize_text_field( (string) ( $row['name'] ?? '' ) ) ) {
				continue;
			}
			if ( (string) $index === $posted_index ) {
				$pos = $i;
				break;
			}
			++$i;
		}
		if ( $pos < 0 || ! isset( $tiers[ $pos ] ) ) {
			add_settings_error( 'mailerhive', 'mh-product', __( 'Give the tier a name before creating its product.', 'mailerhive' ) );
			return $tiers;
		}
		$raw    = isset( $_POST['mh_new_price'][ $posted_index ] ) ? (array) wp_unslash( $_POST['mh_new_price'][ $posted_index ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- options.php verified the nonce; amounts are parsed to integers below.
		$prices = array();
		foreach ( array_keys( Products::OPTIONS ) as $key ) {
			$prices[ $key ] = Products::to_cents( sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) ) );
		}
		$result = Products::create( $tiers[ $pos ], $prices );
		if ( $result['ok'] ) {
			$tiers[ $pos ]['product_id'] = $result['product_id'];
			$tiers[ $pos ]['plans']      = array_merge( $tiers[ $pos ]['plans'], $result['plans'] );
			wp_schedule_single_event( time() + 30, 'mailerhive_paid_reconcile_all' );
		}
		add_settings_error( 'mailerhive', 'mh-product', $result['message'], $result['ok'] ? 'success' : 'error' );
		return $tiers;
	}

	private static function plan_row( string $t, string $p, array $plan, array $vars ): void {
		$n   = static fn( string $k ): string => Settings::OPTION . '[paid_tiers][' . $t . '][plans][' . $p . '][' . $k . ']';
		$vid = (int) ( $plan['variation_id'] ?? 0 );
		$sel = sprintf( '<select name="%s" class="mh-plan-var" aria-label="%s"><option value="0">%s</option>', esc_attr( $n( 'variation_id' ) ), esc_attr__( 'FluentCart price', 'mailerhive' ), esc_html__( '— Choose a product price —', 'mailerhive' ) );
		$grp = '';
		foreach ( $vars as $v ) {
			if ( $v['product'] !== $grp ) {
				$sel .= ( $grp ? '</optgroup>' : '' ) . '<optgroup label="' . esc_attr( $v['product'] . ( 'publish' !== $v['status'] ? ' (' . $v['status'] . ')' : '' ) ) . '">';
				$grp  = $v['product'];
			}
			$terms = 'subscription' === $v['payment_type'] ? Plans::terms_label( array(), $v ) : __( 'one time', 'mailerhive' );
			$sel  .= sprintf( '<option value="%d" data-type="%s"%s>%s — %s %s</option>', (int) $v['id'], esc_attr( $v['payment_type'] ), selected( $vid, (int) $v['id'], false ), esc_html( $v['title'] ?: '#' . $v['id'] ), esc_html( Plans::price( (int) $v['price'] ) ), esc_html( $terms ) );
		}
		$sel .= ( $grp ? '</optgroup>' : '' ) . '</select>';
		$var  = $vid ? Plans::variation( $vid ) : null;
		$sub  = $var && 'subscription' === $var['payment_type'];
		$unit = sprintf( '<select name="%s" aria-label="%s">', esc_attr( $n( 'unit' ) ), esc_attr__( 'Unit', 'mailerhive' ) );
		foreach ( array( 'day' => __( 'days', 'mailerhive' ), 'week' => __( 'weeks', 'mailerhive' ), 'month' => __( 'months', 'mailerhive' ), 'year' => __( 'years', 'mailerhive' ) ) as $u => $label ) {
			$unit .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $u ), selected( (string) ( $plan['unit'] ?? 'month' ), $u, false ), esc_html( $label ) );
		}
		$unit .= '</select>';
		printf(
			'<div class="mh-plan-row">%1$s<div class="mh-plan-access"><span class="mh-plan-sub mh-pill"%2$s><span class="dashicons dashicons-update" aria-hidden="true"></span>%3$s</span><span class="mh-plan-fixed"%4$s><input type="number" min="0" class="small-text" name="%5$s" value="%6$s" aria-label="%7$s"> %8$s<small>%9$s</small></span></div><input type="text" name="%10$s" value="%11$s" placeholder="%12$s" aria-label="%13$s"><button type="button" class="mh-icon-btn mh-remove-plan" aria-label="%14$s" title="%14$s"><span class="dashicons dashicons-no-alt"></span></button></div>',
			$sel, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$sub ? '' : ' hidden',
			esc_html__( 'Follows the subscription', 'mailerhive' ),
			$sub ? ' hidden' : '',
			esc_attr( $n( 'duration' ) ),
			esc_attr( (string) ( $plan['duration'] ?? 12 ) ),
			esc_attr__( 'Access length', 'mailerhive' ),
			$unit, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			esc_html__( '0 = lifetime', 'mailerhive' ),
			esc_attr( $n( 'label' ) ),
			esc_attr( (string) ( $plan['label'] ?? '' ) ),
			esc_attr__( 'Price name', 'mailerhive' ),
			esc_attr__( 'Button label', 'mailerhive' ),
			esc_attr__( 'Remove plan', 'mailerhive' )
		);
	}

	private static function styles(): void {
		echo '<style>
.mh-paid-cell{padding:0!important}
.mh-paid{max-width:980px}
.mh-paid__intro{max-width:720px;margin:4px 0 16px;font-size:14px;line-height:1.6;color:#3c434a}
.mh-ladder{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:12px 0 20px;padding:0;list-style:none}
.mh-ladder li{margin:0}
.mh-ladder__step{display:inline-flex;align-items:center;gap:6px;padding:6px 14px 6px 10px;background:#fff;border:1px solid #dcdcde;border-radius:999px;font-weight:600;color:#1d2327}
.mh-ladder__step .dashicons{font-size:16px;width:16px;height:16px;color:#2271b1}
.mh-ladder__step--free .dashicons{color:#008a20}
.mh-ladder__count{font-weight:400;font-size:12px;color:#646970}
.mh-ladder__arrow{color:#a7aaad;font-size:16px}
.mh-card{margin:0 0 16px;background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.mh-card__head{display:flex;align-items:center;gap:14px;padding:14px 16px 14px 20px;border-bottom:1px solid #f0f0f1}
.mh-card__badge{display:grid;place-items:center;flex:none;width:40px;height:40px;border-radius:10px;background:#f0f6fc;color:#2271b1}
.mh-card--free .mh-card__badge{background:#edfaef;color:#008a20}
.mh-card__title{flex:1;min-width:0}
.mh-paid .mh-card__name{width:100%;max-width:360px;margin:0 0 0 -8px;padding:2px 8px;font-size:17px;font-weight:600;line-height:1.5;border:1px solid transparent;background:transparent;box-shadow:none;color:#1d2327}
.mh-paid .mh-card__name:hover{border-color:#dcdcde}
.mh-paid .mh-card__name:focus{border-color:#2271b1;background:#fff;box-shadow:0 0 0 1px #2271b1}
.mh-card__meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:2px}
.mh-chip{display:inline-flex;align-items:center;padding:1px 9px;border-radius:999px;background:#f0f0f1;color:#50575e;font-size:12px;line-height:20px}
.mh-chip--ok{background:#edfaef;color:#007017}
.mh-card__body{display:grid;gap:22px;padding:20px}
.mh-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px}
.mh-field label,.mh-field__label{display:block;margin:0 0 6px;font-size:13px;font-weight:600;color:#1d2327}
.mh-paid .mh-field input[type=text],.mh-paid .mh-field select,.mh-paid .mh-field textarea{width:100%;max-width:none}
.mh-help{margin:6px 0 0;font-size:12px;line-height:1.5;color:#646970}
.mh-help--flush{margin:0}
.mh-toggle{display:inline-flex;align-items:center;gap:6px}
.mh-section-title{margin:0 0 8px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#646970}
.mh-product{padding:16px;background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:8px}
.mh-product__title{display:flex;align-items:center;gap:6px;margin:0 0 4px;font-size:14px}
.mh-product__title .dashicons{color:#2271b1}
.mh-product--linked{display:flex;flex-wrap:wrap;align-items:center;gap:12px;background:#fff;border-style:solid;border-color:#dcdcde}
.mh-product__icon{display:grid;place-items:center;width:36px;height:36px;border-radius:8px;background:#f0f6fc;color:#2271b1}
.mh-product__main{display:flex;flex-direction:column;flex:1;min-width:200px}
.mh-product__label{font-size:12px;color:#646970}
.mh-product--linked .button .dashicons{font-size:14px;width:14px;height:14px;margin-top:6px}
.mh-product-more{margin-top:-10px}
.mh-product-more summary{padding:4px 0;color:#2271b1;cursor:pointer}
.mh-product-more[open]{padding:12px 16px 16px;background:#f6f7f7;border-radius:8px}
.mh-prices{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin-top:12px}
.mh-field--price label{font-weight:500;font-size:12px;color:#50575e;margin-bottom:4px}
.mh-money{display:flex;align-items:stretch;overflow:hidden;background:#fff;border:1px solid #8c8f94;border-radius:4px}
.mh-money:focus-within{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
.mh-money span{display:flex;align-items:center;padding:0 8px;background:#f6f7f7;color:#50575e;font-size:13px;white-space:nowrap}
.mh-paid .mh-money input{width:72px;min-height:34px;margin:0;border:0;border-radius:0;box-shadow:none;text-align:right}
.mh-create{display:inline-flex!important;align-items:center;gap:4px;min-height:36px!important}
.mh-create .dashicons{font-size:16px;width:16px;height:16px}
.mh-plans-list{overflow:hidden;border:1px solid #dcdcde;border-radius:8px}
.mh-plan-row{display:grid;grid-template-columns:minmax(0,2.2fr) minmax(0,1.4fr) minmax(0,1fr) 32px;gap:12px;align-items:center;padding:10px 12px;border-top:1px solid #f0f0f1}
.mh-plan-rows .mh-plan-row:first-child{border-top:0}
.mh-plan-row--head{padding:8px 12px;background:#f6f7f7;border-bottom:1px solid #dcdcde;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#646970}
.mh-paid .mh-plan-row select,.mh-paid .mh-plan-row input[type=text]{width:100%;max-width:none}
.mh-plan-access small{display:block;margin-top:2px;color:#646970}
.mh-plan-access [hidden]{display:none!important}
.mh-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 10px 2px 6px;border-radius:999px;background:#f0f6fc;color:#135e96;font-size:12px;line-height:22px}
.mh-pill .dashicons{font-size:14px;width:14px;height:14px}
.mh-plan-rows:empty::before{content:attr(data-empty);display:block;padding:18px 16px;text-align:center;color:#646970}
.mh-plans-add{margin:8px 0 0}
.mh-plans-add .button-link,.mh-add-tier-btn{display:inline-flex;align-items:center;gap:4px;text-decoration:none}
.mh-plans-add .dashicons{font-size:16px;width:16px;height:16px}
.mh-add-tier-btn .dashicons{font-size:16px;width:16px;height:16px;margin-top:5px}
.mh-icon-btn{display:grid;place-items:center;width:32px;height:32px;padding:0;border:0;border-radius:6px;background:none;color:#8c8f94;cursor:pointer}
.mh-icon-btn:hover,.mh-icon-btn:focus{background:#fcf0f1;color:#b32d2e}
@media (max-width:782px){.mh-grid{grid-template-columns:1fr}.mh-plan-row{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 32px}.mh-plan-row > select{grid-column:1 / -1}.mh-plan-row--head{display:none}.mh-card__body{padding:16px}}
</style>';
	}

	private static function script(): void {
		?>
<script>
(function(){
	var wrap=document.getElementById('mh-tiers'),tt=document.getElementById('mh-tier-tpl'),pt=document.getElementById('mh-plan-tpl'),n=Date.now();
	function sync(row){var s=row.querySelector('.mh-plan-var'),o=s.options[s.selectedIndex],sub=o&&o.getAttribute('data-type')==='subscription';row.querySelector('.mh-plan-sub').hidden=!sub;row.querySelector('.mh-plan-fixed').hidden=sub;}
	document.getElementById('mh-add-tier').addEventListener('click',function(){var t='n'+(n++);wrap.insertAdjacentHTML('beforeend',tt.innerHTML.split('__T__').join(t));var c=wrap.lastElementChild;c.querySelector('.mh-card__name').focus();});
	document.addEventListener('click',function(e){
		var b=e.target.closest('button');if(!b)return;
		if(b.classList.contains('mh-add-plan')){var t=b.getAttribute('data-tier');b.closest('.mh-tier-box').querySelector('.mh-plan-rows').insertAdjacentHTML('beforeend',pt.innerHTML.split('__T__').join(t).split('__P__').join('n'+(n++)));}
		if(b.classList.contains('mh-remove-plan')){b.closest('.mh-plan-row').remove();}
		if(b.classList.contains('mh-remove-tier')&&confirm(<?php echo wp_json_encode( __( 'Remove this tier? Issues set to it become free, and its readers are taken off its list at their next update.', 'mailerhive' ) ); ?>)){b.closest('.mh-tier-box').remove();}
	});
	document.addEventListener('change',function(e){if(e.target.classList.contains('mh-plan-var')){sync(e.target.closest('.mh-plan-row'));}});
})();
</script>
		<?php
	}

	private static function heading_row( string $text ): void {
		echo '</table><h2 class="title">' . esc_html( $text ) . '</h2><table class="form-table" role="presentation">';
	}

	private static function number_row( array $s, string $key, string $label, string $help ): void {
		printf( '<tr><th scope="row"><label for="mh-%1$s">%2$s</label></th><td><input type="number" min="0" class="small-text" id="mh-%1$s" name="%3$s" value="%4$d"><p class="description">%5$s</p></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( Settings::OPTION . '[' . $key . ']' ), (int) $s[ $key ], esc_html( $help ) );
	}

	private static function check_row( array $s, string $key, string $label, string $text, string $help = '' ): void {
		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1"%s> %s</label>%s</td></tr>', esc_html( $label ), esc_attr( Settings::OPTION . '[' . $key . ']' ), checked( ! empty( $s[ $key ] ), true, false ), esc_html( $text ), $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' );
	}

	/** Called from Settings::sanitize() for the Paid tab. */
	public static function sanitize( array $in, array $out ): array {
		$before            = wp_list_pluck( Plans::tiers(), 'list_id', 'key' );
		$out['paid_tiers'] = Plans::sanitize( $in['paid_tiers'] ?? array() );
		foreach ( array( 'paid_preview_blocks' => array( 1, 50 ), 'paid_grace_days' => array( 0, 60 ), 'paid_cookie_days' => array( 1, 400 ) ) as $k => $range ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = min( $range[1], max( $range[0], absint( $in[ $k ] ) ) );
			}
		}
		foreach ( array( 'paid_plans_heading' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		if ( isset( $in['paid_plans_intro'] ) ) {
			$out['paid_plans_intro'] = sanitize_textarea_field( $in['paid_plans_intro'] );
		}
		if ( isset( $in['free_tier_name'] ) ) {
			$out['free_tier_name'] = sanitize_text_field( $in['free_tier_name'] ) ?: __( 'Free', 'mailerhive' );
		}
		if ( isset( $in['free_tier_description'] ) ) {
			$out['free_tier_description'] = sanitize_textarea_field( $in['free_tier_description'] );
		}
		if ( isset( $in['_create_product'] ) ) {
			$out['paid_tiers'] = self::create_product_for( (string) $in['_create_product'], (array) ( $in['paid_tiers'] ?? array() ), $out['paid_tiers'] );
		}
		// A changed tier map or list needs every reader re-checked once.
		$after = wp_list_pluck( $out['paid_tiers'], 'list_id', 'key' );
		if ( $before !== $after || wp_json_encode( Plans::tiers() ) !== wp_json_encode( $out['paid_tiers'] ) ) {
			wp_schedule_single_event( time() + 30, 'mailerhive_paid_reconcile_all' );
		}
		return $out;
	}

	/* ---------- members screen ---------- */

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Issues::TYPE, __( 'Paid members', 'mailerhive' ), __( 'Paid members', 'mailerhive' ), 'manage_options', self::PAGE, array( __CLASS__, 'screen' ) );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . Issues::TYPE . '&page=' . self::PAGE ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'mailerhive' ) );
		}
		check_admin_referer( 'mailerhive_members' );
		$op  = sanitize_key( wp_unslash( $_REQUEST['op'] ?? '' ) );
		$msg = '';
		if ( 'grant' === $op ) {
			$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$tier    = sanitize_key( wp_unslash( $_POST['tier'] ?? '' ) );
			$until   = sanitize_text_field( wp_unslash( $_POST['until'] ?? '' ) );
			$expires = $until && strtotime( $until ) ? gmdate( 'Y-m-d 23:59:59', strtotime( $until ) ) : null;
			$id      = Members::grant( $email, $tier, $expires, sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) );
			/* translators: 1: email, 2: tier */
			$msg = $id ? sprintf( __( '%1$s now has %2$s access.', 'mailerhive' ), $email, Plans::name( $tier ) ) : __( 'Enter a valid email and tier.', 'mailerhive' );
		} elseif ( 'revoke' === $op ) {
			$msg = Members::revoke( absint( $_REQUEST['id'] ?? 0 ) ) ? __( 'Access revoked.', 'mailerhive' ) : __( 'Only manual grants can be revoked here. Cancel or refund FluentCart purchases in FluentCart.', 'mailerhive' );
		} elseif ( 'reconcile' === $op ) {
			/* translators: %d: number of customers */
			$msg = sprintf( __( 'Re-checked %d FluentCart customer(s).', 'mailerhive' ), Members::reconcile_all() );
		}
		set_transient( 'mailerhive_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function screen(): void {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tier   = sanitize_key( wp_unslash( $_GET['tier'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state  = sanitize_key( wp_unslash( $_GET['state'] ?? 'live' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 50;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$live   = "'" . implode( "','", Members::LIVE ) . "'";
		$where  = array( '1=1' );
		$args   = array();
		if ( $tier ) {
			$where[] = 'tier = %s';
			$args[]  = $tier;
		}
		if ( 'live' === $state ) {
			$where[] = "status IN ({$live}) AND (expires_at IS NULL OR expires_at > %s)";
			$args[]  = $now;
		} elseif ( 'ended' === $state ) {
			$where[] = "NOT (status IN ({$live}) AND (expires_at IS NULL OR expires_at > %s))";
			$args[]  = $now;
		}
		if ( $search ) {
			$where[] = 'email LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( strtolower( $search ) ) . '%';
		}
		$sql   = 'FROM ' . Members::table() . ' WHERE ' . implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) {$sql}", $args ) : "SELECT COUNT(*) {$sql}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * {$sql} ORDER BY updated_at DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $paged - 1 ) * $per ) ) ) ) ?: array();

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Paid members', 'mailerhive' ) . '</h1>';
		$notice = get_transient( 'mailerhive_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'mailerhive_notice_' . get_current_user_id() );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		if ( ! Plans::tiers() ) {
			echo '<div class="notice notice-info"><p><a href="' . esc_url( add_query_arg( 'tab', 'paid', Settings::url() ) ) . '">' . esc_html__( 'Set up a paid tier first.', 'mailerhive' ) . '</a></p></div></div>';
			return;
		}

		$counts = Members::counts();
		echo '<p>';
		foreach ( Plans::tiers() as $t ) {
			/* translators: 1: tier name, 2: count */
			echo '<span style="margin-right:1.25rem"><strong>' . esc_html( $t['name'] ) . '</strong>: ' . (int) ( $counts[ $t['key'] ] ?? 0 ) . '</span>';
		}
		echo '</p>';

		echo '<form method="get" style="margin:1rem 0"><input type="hidden" name="post_type" value="' . esc_attr( Issues::TYPE ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<select name="tier"><option value="">' . esc_html__( 'All tiers', 'mailerhive' ) . '</option>';
		foreach ( Plans::tiers() as $t ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $t['key'] ), selected( $tier, $t['key'], false ), esc_html( $t['name'] ) );
		}
		echo '</select> <select name="state">';
		foreach ( array( 'live' => __( 'Active', 'mailerhive' ), 'ended' => __( 'Ended', 'mailerhive' ), 'all' => __( 'All', 'mailerhive' ) ) as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $state, $k, false ), esc_html( $label ) );
		}
		echo '</select> <input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Email', 'mailerhive' ) . '"> ';
		submit_button( __( 'Filter', 'mailerhive' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Email', 'mailerhive' ) . '</th><th>' . esc_html__( 'Tier', 'mailerhive' ) . '</th><th>' . esc_html__( 'Access', 'mailerhive' ) . '</th><th>' . esc_html__( 'Source', 'mailerhive' ) . '</th><th>' . esc_html__( 'Started', 'mailerhive' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No members match.', 'mailerhive' ) . '</td></tr>';
		}
		$fc = admin_url( 'admin.php?page=fluent-cart#/' );
		foreach ( $rows as $r ) {
			if ( $r->subscription_id ) {
				$source = '<a href="' . esc_url( $fc . 'subscriptions/' . (int) $r->subscription_id . '/view' ) . '">' . esc_html( sprintf( /* translators: %d: id */ __( 'Subscription #%d', 'mailerhive' ), $r->subscription_id ) ) . '</a>';
			} elseif ( $r->order_id ) {
				$source = '<a href="' . esc_url( $fc . 'orders/' . (int) $r->order_id . '/view' ) . '">' . esc_html( sprintf( /* translators: %d: id */ __( 'Order #%d', 'mailerhive' ), $r->order_id ) ) . '</a> · ' . esc_html( 'lifetime' === $r->mode ? __( 'lifetime', 'mailerhive' ) : __( 'fixed term', 'mailerhive' ) );
			} else {
				$source = esc_html__( 'Manual', 'mailerhive' ) . ( $r->note ? ' · <em>' . esc_html( $r->note ) . '</em>' : '' );
			}
			$action = '';
			if ( 'manual' === $r->mode && Members::is_live( $r ) ) {
				$action = '<a class="button-link-delete" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'mailerhive_members', 'op' => 'revoke', 'id' => (int) $r->id ), admin_url( 'admin-post.php' ) ), 'mailerhive_members' ) ) . '">' . esc_html__( 'Revoke', 'mailerhive' ) . '</a>';
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $r->email ),
				esc_html( Plans::name( (string) $r->tier ) ),
				esc_html( Access::status_label( $r ) ),
				$source, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( $r->starts_at ? wp_date( get_option( 'date_format' ), strtotime( $r->starts_at . ' UTC' ) ) : '' ),
				$action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			);
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p>' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</p>';
		}

		echo '<h2 style="margin-top:2rem">' . esc_html__( 'Give access by hand', 'mailerhive' ) . '</h2><p class="description">' . esc_html__( 'For comps, gifts and team members. Leave the date empty for access with no end.', 'mailerhive' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
		wp_nonce_field( 'mailerhive_members' );
		echo '<input type="hidden" name="action" value="mailerhive_members"><input type="hidden" name="op" value="grant">';
		echo '<input type="email" name="email" required placeholder="' . esc_attr__( 'reader@example.com', 'mailerhive' ) . '" class="regular-text"> <select name="tier">';
		foreach ( Plans::tiers() as $t ) {
			printf( '<option value="%s">%s</option>', esc_attr( $t['key'] ), esc_html( $t['name'] ) );
		}
		echo '</select> <label>' . esc_html__( 'Until', 'mailerhive' ) . ' <input type="date" name="until"></label> <input type="text" name="note" placeholder="' . esc_attr__( 'Note (optional)', 'mailerhive' ) . '"> ';
		submit_button( __( 'Give access', 'mailerhive' ), 'primary', '', false );
		echo '</form>';

		echo '<h2 style="margin-top:2rem">' . esc_html__( 'Re-check FluentCart', 'mailerhive' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mailerhive_members' );
		echo '<input type="hidden" name="action" value="mailerhive_members"><input type="hidden" name="op" value="reconcile"><p class="description">' . esc_html__( 'Rebuilds every paid reader from FluentCart orders and subscriptions, then updates their MailerPress lists and tags. Safe to run any time.', 'mailerhive' ) . '</p>';
		submit_button( __( 'Re-check all purchases', 'mailerhive' ), 'secondary', '', false );
		echo '</form></div>';
	}
}
