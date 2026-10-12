<?php
/**
 * One-click FluentCart products for paid tiers.
 *
 * "Create FluentCart product" on a tier builds a published digital product named after
 * the newsletter and tier, with a price for each billing option filled in (monthly and
 * yearly subscriptions, an optional one-time lifetime price), through FluentCart's own
 * product and pricing resources. The new prices are mapped to the tier as plans, so the
 * paywall and plans show them immediately.
 */

namespace MailerHive\Modules\Paid;

use MailerHive\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- FluentCart product detail row; table name from $wpdb->prefix, values prepared by $wpdb->update().

final class Products {
	/** Billing options the button can create, in display order. */
	const OPTIONS = array(
		'monthly'  => array( 'type' => 'subscription', 'interval' => 'monthly' ),
		'yearly'   => array( 'type' => 'subscription', 'interval' => 'yearly' ),
		'lifetime' => array( 'type' => 'onetime', 'interval' => '' ),
	);

	public static function ready(): bool {
		return class_exists( '\FluentCart\Api\Resource\ProductResource' ) && class_exists( '\FluentCart\Api\Resource\ProductVariationResource' );
	}

	public static function labels(): array {
		return array(
			'monthly'  => __( 'Monthly', 'mailerhive' ),
			'yearly'   => __( 'Yearly', 'mailerhive' ),
			'lifetime' => __( 'Lifetime', 'mailerhive' ),
		);
	}

	public static function defaults(): array {
		return array( 'monthly' => '5', 'yearly' => '50', 'lifetime' => '' );
	}

	/** "5", "4.99", "1,299.00" → cents; '' or 0 → 0 (option skipped). */
	public static function to_cents( string $amount ): int {
		$amount = preg_replace( '/[^0-9.,]/', '', $amount );
		if ( '' === $amount ) {
			return 0;
		}
		if ( preg_match( '/,\d{1,2}$/', $amount ) ) {
			$amount = str_replace( array( '.', ',' ), array( '', '.' ), $amount ); // 1.299,00
		} else {
			$amount = str_replace( ',', '', $amount );
		}
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Create the product and map its prices to the tier.
	 *
	 * @param array $tier   Sanitized tier.
	 * @param array $prices option => cents.
	 * @return array{ok:bool,message:string,product_id:int,plans:array}
	 */
	public static function create( array $tier, array $prices ): array {
		global $wpdb;
		$fail = static fn( string $m ) => array( 'ok' => false, 'message' => $m, 'product_id' => 0, 'plans' => array() );
		if ( ! self::ready() ) {
			return $fail( __( 'FluentCart is not active.', 'mailerhive' ) );
		}
		$prices = array_filter( array_intersect_key( $prices, self::OPTIONS ) );
		if ( ! $prices ) {
			return $fail( __( 'Enter at least one price.', 'mailerhive' ) );
		}

		/* translators: 1: newsletter name, 2: tier name */
		$title   = sprintf( __( '%1$s: %2$s membership', 'mailerhive' ), Settings::brand(), $tier['name'] );
		$created = \FluentCart\Api\Resource\ProductResource::create(
			array(
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_excerpt' => (string) $tier['description'],
				'post_content' => $tier['description'] ? '<!-- wp:paragraph --><p>' . esc_html( $tier['description'] ) . '</p><!-- /wp:paragraph -->' : '',
				'detail'       => array(
					'fulfillment_type' => 'digital',
					'variation_type'   => count( $prices ) > 1 ? 'simple_variations' : 'simple',
					'manage_stock'     => 0,
					'other_info'       => array(
						'active_editor'     => 'gutenberg',
						'group_pricing_by'  => 'payment_type',
						'sold_individually' => 'yes',
						'is_bundle_product' => 'no',
					),
				),
			)
		);
		if ( is_wp_error( $created ) || empty( $created['data']['ID'] ) ) {
			return $fail( is_wp_error( $created ) ? $created->get_error_message() : __( 'FluentCart could not create the product.', 'mailerhive' ) );
		}
		$product_id = (int) $created['data']['ID'];
		Visibility::mark( $product_id );
		$detail     = $created['data']['product_details'] ?? null;
		$detail_id  = (int) ( is_object( $detail ) ? ( $detail->id ?? 0 ) : ( $detail['id'] ?? 0 ) );

		$labels = self::labels();
		$notes  = array(
			'monthly'  => __( 'Renews every month. Cancel anytime.', 'mailerhive' ),
			'yearly'   => __( 'Renews every year. Cancel anytime.', 'mailerhive' ),
			'lifetime' => __( 'One payment, access for as long as the newsletter runs.', 'mailerhive' ),
		);
		$plans  = array();
		$first  = 0;
		$serial = 1;
		foreach ( self::OPTIONS as $key => $opt ) {
			if ( empty( $prices[ $key ] ) ) {
				continue;
			}
			$other = 'subscription' === $opt['type']
				? array(
					'description'      => $notes[ $key ],
					'payment_type'     => 'subscription',
					'repeat_interval'  => $opt['interval'],
					'trial_days'       => '0',
					'manage_setup_fee' => 'no',
					'installment'      => 'no',
					'times'            => '',
					'is_bundle_product' => 'no',
				)
				: array(
					'description'  => $notes[ $key ],
					'payment_type' => 'onetime',
				);
			$res = \FluentCart\Api\Resource\ProductVariationResource::create(
				array(
					'post_id'          => $product_id,
					'detail_id'        => $detail_id,
					'serial_index'     => $serial++,
					'variation_title'  => $labels[ $key ],
					'item_price'       => (int) $prices[ $key ],
					'compare_price'    => '',
					'manage_stock'     => 0,
					'fulfillment_type' => 'digital',
					'downloadable'     => false,
					'other_info'       => $other,
				)
			);
			$vid = is_array( $res ) && isset( $res['data'] ) ? (int) ( is_object( $res['data'] ) ? $res['data']->id : ( $res['data']['id'] ?? 0 ) ) : 0;
			if ( $vid ) {
				$first   = $first ?: $vid;
				$plans[] = array( 'variation_id' => $vid, 'duration' => 0, 'unit' => 'month', 'label' => $labels[ $key ] );
			}
		}
		if ( ! $plans ) {
			wp_trash_post( $product_id );
			return $fail( __( 'FluentCart created the product but not its prices, so it was moved to the trash.', 'mailerhive' ) );
		}
		if ( $detail_id && $first ) {
			$wpdb->update( $wpdb->prefix . 'fct_product_details', array( 'default_variation_id' => $first ), array( 'id' => $detail_id ), array( '%d' ), array( '%d' ) );
			if ( class_exists( '\FluentCart\Api\Resource\ProductDetailResource' ) ) {
				\FluentCart\Api\Resource\ProductDetailResource::update( array(), $detail_id, array( 'action' => 'variant_modified' ) );
			}
		}
		return array(
			'ok'         => true,
			/* translators: 1: product title, 2: number of prices */
			'message'    => sprintf( _n( 'Created “%1$s” in FluentCart with %2$d price and added it to the tier.', 'Created “%1$s” in FluentCart with %2$d prices and added them to the tier.', count( $plans ), 'mailerhive' ), $title, count( $plans ) ),
			'product_id' => $product_id,
			'plans'      => $plans,
		);
	}

	public static function edit_url( int $product_id ): string {
		return admin_url( 'admin.php?page=fluent-cart#/products/' . $product_id );
	}
}
