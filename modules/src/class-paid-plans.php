<?php
/**
 * Paid tiers and the FluentCart plans that grant them.
 *
 * A tier is a level of access ("Supporter", "Insider"), ordered lowest to highest, with
 * its own MailerPress list. A plan is a FluentCart product variation mapped to a tier.
 * The variation's own payment type decides how access is timed:
 *
 * - subscription variation: access follows the FluentCart subscription (renewals,
 *   cancellations, failed payments, pauses);
 * - one-time variation with a duration: a fixed term ("12 months"), and buying again
 *   extends the term instead of overlapping it;
 * - one-time variation without a duration: lifetime access.
 */

namespace MailerHive\Modules\Paid;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FluentCart keeps products in custom tables; table names come from $wpdb->prefix and values are prepared.

final class Plans {
	const UNITS = array( 'day', 'week', 'month', 'year' );

	private static $variations = null;

	/** True once variations() has loaded the full catalog (or confirmed it cannot). */
	private static $catalog_complete = false;

	/** True when the variations query failed, so an empty result is not a real catalog. */
	private static $catalog_error = false;

	/**
	 * Tiers in rank order (index 0 is the lowest).
	 *
	 * @return array<int,array{key:string,name:string,description:string,list_id:int,plans:array}>
	 */
	public static function tiers(): array {
		$out = array();
		foreach ( (array) \MailerHive\Settings::get( 'paid_tiers' ) as $tier ) {
			if ( is_array( $tier ) && ! empty( $tier['key'] ) && ! empty( $tier['name'] ) ) {
				$out[] = $tier + array( 'description' => '', 'list_id' => 0, 'product_id' => 0, 'plans' => array() );
			}
		}
		return $out;
	}

	public static function tier( string $key ): ?array {
		foreach ( self::tiers() as $tier ) {
			if ( $tier['key'] === $key ) {
				return $tier;
			}
		}
		return null;
	}

	/** 1-based rank, 0 for an unknown tier. */
	public static function rank( string $key ): int {
		foreach ( self::tiers() as $i => $tier ) {
			if ( $tier['key'] === $key ) {
				return $i + 1;
			}
		}
		return 0;
	}

	public static function name( string $key ): string {
		$tier = self::tier( $key );
		return $tier ? (string) $tier['name'] : $key;
	}

	public static function tier_list_ids(): array {
		return array_values( array_filter( array_map( static fn( $t ) => (int) $t['list_id'], self::tiers() ) ) );
	}

	/** Tiers at or above a rank. */
	public static function tiers_from( int $rank ): array {
		return array_slice( self::tiers(), max( 0, $rank - 1 ) );
	}

	/**
	 * Every mapped variation with its timing.
	 *
	 * @return array<int,array{tier:string,mode:string,duration:int,unit:string}>
	 */
	public static function by_variation(): array {
		$wanted = array();
		foreach ( self::tiers() as $tier ) {
			foreach ( (array) $tier['plans'] as $plan ) {
				$vid = (int) ( $plan['variation_id'] ?? 0 );
				if ( $vid && ! isset( $wanted[ $vid ] ) ) {
					$wanted[ $vid ] = $plan;
					$wanted[ $vid ]['tier'] = (string) $tier['key'];
				}
			}
		}
		if ( ! $wanted ) {
			return array();
		}
		$rows = self::query_variations( array_keys( $wanted ) );
		if ( null === $rows ) {
			self::$catalog_error = true;
			return array();
		}
		$out = array();
		foreach ( $rows as $var ) {
			$plan = $wanted[ $var['id'] ] ?? null;
			if ( ! $plan || isset( $out[ $var['id'] ] ) ) {
				continue;
			}
			$duration         = max( 0, (int) ( $plan['duration'] ?? 0 ) );
			$out[ $var['id'] ] = array(
				'tier'     => (string) $plan['tier'],
				'mode'     => 'subscription' === $var['payment_type'] ? 'subscription' : ( $duration ? 'fixed' : 'lifetime' ),
				'duration' => $duration,
				'unit'     => in_array( $plan['unit'] ?? '', self::UNITS, true ) ? $plan['unit'] : 'month',
			);
		}
		return $out;
	}

	/** The variations query failed in this request. An empty plan map is not trustworthy. */
	public static function catalog_failed(): bool {
		return self::$catalog_error;
	}

	/** End of a fixed term that starts at $start (UTC "Y-m-d H:i:s"). */
	public static function add_term( string $start, int $duration, string $unit ): string {
		$date = new \DateTimeImmutable( $start, new \DateTimeZone( 'UTC' ) );
		return $date->modify( sprintf( '+%d %s', $duration, $unit ) )->format( 'Y-m-d H:i:s' );
	}

	public static function fluentcart_ready(): bool {
		global $wpdb;
		return defined( 'FLUENTCART_VERSION' ) || class_exists( '\FluentCart\App\App' ) || (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'fct_product_variations' ) );
	}

	/**
	 * FluentCart variations for the plan picker and price display.
	 *
	 * @return array<int,array{id:int,product_id:int,product:string,title:string,price:int,payment_type:string,interval:string,status:string}>
	 */
	public static function variations(): array {
		if ( self::$catalog_complete && is_array( self::$variations ) ) {
			return self::$variations;
		}
		$rows = self::query_variations( null );
		if ( null === $rows ) {
			self::$catalog_error = true;
			return array();
		}
		self::$variations       = array();
		self::$catalog_complete = true;
		foreach ( $rows as $var ) {
			self::$variations[ $var['id'] ] = $var;
		}
		return self::$variations;
	}

	/**
	 * Variation rows for the plan picker. Null means the query failed (not an empty catalog).
	 *
	 * @param array<int>|null $ids Limit to these variation ids. Null loads the catalog.
	 * @return array<int,array{id:int,product_id:int,product:string,title:string,price:int,payment_type:string,interval:string,status:string}>|null
	 */
	private static function query_variations( ?array $ids ): ?array {
		global $wpdb;
		if ( ! self::fluentcart_ready() ) {
			return array();
		}
		$where = "p.post_status NOT IN ('trash','auto-draft')";
		if ( null !== $ids ) {
			$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
			if ( ! $ids ) {
				return array();
			}
			$where .= ' AND v.id IN (' . implode( ',', $ids ) . ')';
		}
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results(
			"SELECT v.id, v.post_id, v.variation_title, v.item_price, v.payment_type, v.other_info, p.post_title, p.post_status
			 FROM {$wpdb->prefix}fct_product_variations v
			 INNER JOIN {$wpdb->posts} p ON p.ID = v.post_id
			 WHERE {$where}
			 ORDER BY p.post_title ASC, v.serial_index ASC, v.id ASC"
		);
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $r ) {
			$info   = json_decode( (string) $r->other_info, true );
			$out[] = array(
				'id'           => (int) $r->id,
				'product_id'   => (int) $r->post_id,
				'product'      => (string) $r->post_title,
				'title'        => (string) $r->variation_title,
				'price'        => (int) $r->item_price,
				'payment_type' => 'subscription' === $r->payment_type ? 'subscription' : 'onetime',
				'interval'     => is_array( $info ) ? (string) ( $info['repeat_interval'] ?? '' ) : '',
				'status'       => (string) $r->post_status,
			);
		}
		return $out;
	}

	public static function variation( int $id ): ?array {
		if ( self::$catalog_complete ) {
			return self::variations()[ $id ] ?? null;
		}
		if ( is_array( self::$variations ) && isset( self::$variations[ $id ] ) ) {
			return self::$variations[ $id ];
		}
		if ( self::$catalog_error ) {
			return null;
		}
		$rows = self::query_variations( array( $id ) );
		if ( null === $rows ) {
			self::$catalog_error = true;
			return null;
		}
		if ( ! isset( $rows[0] ) ) {
			return null;
		}
		if ( ! is_array( self::$variations ) ) {
			self::$variations = array();
		}
		self::$variations[ $id ] = $rows[0];
		return $rows[0];
	}

	public static function checkout_url( int $variation_id ): string {
		return add_query_arg(
			array(
				'fluent-cart' => 'instant_checkout',
				'item_id'     => $variation_id,
				'quantity'    => 1,
			),
			site_url( '/' )
		);
	}

	/** GT Extensions for FluentCart's multi-currency module, when it is running. */
	public static function multi_currency(): ?object {
		if ( ! function_exists( 'gt_extensions_fluentcart' ) ) {
			return null;
		}
		try {
			$module = gt_extensions_fluentcart()->getModule( 'multi_currency_variations' );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_object( $module ) && method_exists( $module, 'resolvePrice' ) && method_exists( $module, 'getCurrentCurrency' ) ? $module : null;
	}

	/** The store's own currency: what variation prices are stored in. */
	public static function base_currency(): string {
		if ( class_exists( '\GTExtensionsFluentCart\Modules\MultiCurrencyVariations\Settings' ) ) {
			$code = (string) \GTExtensionsFluentCart\Modules\MultiCurrencyVariations\Settings::storeCurrency();
			if ( '' !== $code ) {
				return $code;
			}
		}
		if ( class_exists( '\FluentCart\App\Helpers\Helper' ) ) {
			return (string) ( \FluentCart\App\Helpers\Helper::shopConfig( 'currency' ) ?: 'USD' );
		}
		return 'USD';
	}

	/** Format minor units in an explicit currency (never the visitor-filtered default). */
	public static function format( int $cents, string $currency ): string {
		if ( class_exists( '\FluentCart\Api\CurrencySettings' ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( (string) \FluentCart\Api\CurrencySettings::getPriceHtml( $cents, $currency, 0 !== $cents % 100 ) ), ENT_QUOTES, 'UTF-8' ) );
		}
		return $currency . ' ' . number_format_i18n( $cents / 100, 0 !== $cents % 100 ? 2 : 0 );
	}

	/** Base-currency price, as stored on the variation. */
	public static function price( int $cents ): string {
		return self::format( $cents, self::base_currency() );
	}

	/**
	 * A variation's price in a currency, resolved the way GT Extensions resolves it at
	 * checkout (manual price book first, then the exchange rate). '' when that currency
	 * has no price and the store blocks conversion.
	 */
	public static function price_in( int $variation_id, string $currency ): string {
		$var = self::variation( $variation_id );
		if ( ! $var ) {
			return '';
		}
		$base = self::base_currency();
		if ( $currency === $base ) {
			return self::format( (int) $var['price'], $base );
		}
		$module = self::multi_currency();
		if ( ! $module || ! class_exists( '\FluentCart\App\Models\ProductVariation' ) ) {
			return '';
		}
		try {
			$resolved = $module->resolvePrice( \FluentCart\App\Models\ProductVariation::query()->find( $variation_id ), $currency );
		} catch ( \Throwable $e ) {
			return '';
		}
		return $resolved ? self::format( (int) $resolved['item_price'], $currency ) : '';
	}

	public static function currency_sign(): string {
		if ( class_exists( '\FluentCart\App\Helpers\CurrenciesHelper' ) && class_exists( '\FluentCart\App\Helpers\Helper' ) ) {
			try {
				return html_entity_decode( (string) \FluentCart\App\Helpers\CurrenciesHelper::getCurrencySign( self::base_currency() ), ENT_QUOTES, 'UTF-8' );
			} catch ( \Throwable $e ) {
				return '$';
			}
		}
		return '$';
	}

	/** "per year", "for 12 months", "one time". */
	public static function terms_label( array $plan, array $variation ): string {
		if ( 'subscription' === $variation['payment_type'] ) {
			$map = array(
				'daily'       => __( 'per day', 'mailerhive' ),
				'weekly'      => __( 'per week', 'mailerhive' ),
				'monthly'     => __( 'per month', 'mailerhive' ),
				'quarterly'   => __( 'per quarter', 'mailerhive' ),
				'half_yearly' => __( 'every 6 months', 'mailerhive' ),
				'yearly'      => __( 'per year', 'mailerhive' ),
			);
			return $map[ $variation['interval'] ] ?? __( 'recurring', 'mailerhive' );
		}
		$duration = (int) ( $plan['duration'] ?? 0 );
		if ( ! $duration ) {
			return __( 'one time, no renewal', 'mailerhive' );
		}
		$units = array(
			/* translators: %d: number of days */
			'day'   => _n( 'for %d day', 'for %d days', $duration, 'mailerhive' ),
			/* translators: %d: number of weeks */
			'week'  => _n( 'for %d week', 'for %d weeks', $duration, 'mailerhive' ),
			/* translators: %d: number of months */
			'month' => _n( 'for %d month', 'for %d months', $duration, 'mailerhive' ),
			/* translators: %d: number of years */
			'year'  => _n( 'for %d year', 'for %d years', $duration, 'mailerhive' ),
		);
		return sprintf( $units[ $plan['unit'] ?? 'month' ] ?? $units['month'], $duration );
	}

	public static function sanitize( $tiers ): array {
		$out  = array();
		$keys = array();
		foreach ( is_array( $tiers ) ? $tiers : array() as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) ( $tier['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$key = sanitize_key( (string) ( $tier['key'] ?? '' ) ) ?: sanitize_key( sanitize_title( $name ) );
			$key = $key ?: 'tier';
			$base = $key;
			for ( $i = 2; isset( $keys[ $key ] ); $i++ ) {
				$key = $base . '-' . $i;
			}
			$keys[ $key ] = true;

			$list_id = (int) ( $tier['list_id'] ?? 0 );
			if ( -1 === $list_id ) {
				/* translators: %s: tier name */
				$list_id = \MailerHive\MP_Store::create_list( sprintf( __( '%s subscribers', 'mailerhive' ), $name ), __( 'Managed by MailerHive from FluentCart purchases.', 'mailerhive' ) );
			}

			$plans = array();
			$seen  = array();
			foreach ( (array) ( $tier['plans'] ?? array() ) as $plan ) {
				$vid = absint( $plan['variation_id'] ?? 0 );
				if ( ! $vid || isset( $seen[ $vid ] ) ) {
					continue;
				}
				$seen[ $vid ] = true;
				$plans[]      = array(
					'variation_id' => $vid,
					'duration'     => min( 1000, absint( $plan['duration'] ?? 0 ) ),
					'unit'         => in_array( $plan['unit'] ?? '', self::UNITS, true ) ? $plan['unit'] : 'month',
					'label'        => sanitize_text_field( (string) ( $plan['label'] ?? '' ) ),
				);
			}

			$out[] = array(
				'key'         => $key,
				'name'        => $name,
				'description' => sanitize_textarea_field( (string) ( $tier['description'] ?? '' ) ),
				'list_id'     => max( 0, $list_id ),
				'product_id'  => absint( $tier['product_id'] ?? 0 ),
				'plans'       => $plans,
			);
		}
		return $out;
	}
}
