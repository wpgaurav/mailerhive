<?php
/**
 * Keep MailerHive's subscription products out of the storefront.
 *
 * A paid newsletter is sold from the paywall and the plans, so the FluentCart product
 * behind it does not belong in the shop grid next to themes and templates. Unless the
 * owner opts in (Paid tab → "Show in the shop"), MailerHive's products are left out of
 * the FluentCart shop and search, related products, product archives, site search and
 * sitemaps. The product page and checkout links keep working; the page is marked
 * noindex so search engines do not list it either.
 */

namespace MailerHive\Modules\Paid;

use MailerHive\Settings;

defined( 'ABSPATH' ) || exit;

final class Visibility {
	const META = '_mailerhive_paid_product';

	private static $ids = null;

	public static function boot(): void {
		if ( Settings::on( 'paid_products_in_shop' ) ) {
			return;
		}
		add_filter( 'fluent_cart/shop_query', array( __CLASS__, 'shop_query' ), 20, 2 );
		add_filter( 'fluent_cart/related_products/query_args', array( __CLASS__, 'exclude_args' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'archives' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_args' ) );
		add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'rank_math_entry' ), 10, 3 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'rank_math_robots' ) );
	}

	/** Products MailerHive created, plus any product a tier is linked to. */
	public static function ids(): array {
		if ( null !== self::$ids ) {
			return self::$ids;
		}
		$ids = get_posts(
			array(
				'post_type'        => 'fluent-products',
				'post_status'      => 'any',
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-created set.
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'mailerhive_include_paid' => true,
			)
		);
		foreach ( Plans::tiers() as $tier ) {
			if ( ! empty( $tier['product_id'] ) ) {
				$ids[] = (int) $tier['product_id'];
			}
		}
		self::$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		return self::$ids;
	}

	public static function mark( int $product_id ): void {
		update_post_meta( $product_id, self::META, 1 );
		self::$ids = null;
	}

	public static function is_hidden( int $post_id ): bool {
		return in_array( $post_id, self::ids(), true );
	}

	/** FluentCart's shop, search bar and shop blocks (storefront only; the admin list uses its own query). */
	public static function shop_query( $query, $params = array() ) {
		$ids = self::ids();
		if ( ! $ids || ! is_object( $query ) ) {
			return $query;
		}
		try {
			// The ORM builder forwards whereNotIn() through __call, so method_exists() is false.
			return $query->whereNotIn( 'ID', $ids );
		} catch ( \Throwable $e ) {
			return $query;
		}
	}

	/** WP_Query-style args: related products and the core sitemap. */
	public static function exclude_args( $args ) {
		$ids = self::ids();
		if ( $ids && is_array( $args ) ) {
			$args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), $ids ) ) );
		}
		return $args;
	}

	/**
	 * Any front-end listing of FluentCart products: product archives, category and brand
	 * archives, site search, and custom shop grids that run their own WP_Query or
	 * get_posts(). Single product lookups (p, name or post__in) are left alone, and a
	 * query can opt back in with the `mailerhive_include_paid` query var.
	 */
	public static function archives( \WP_Query $query ): void {
		if ( ( is_admin() && ! wp_doing_ajax() ) || $query->is_singular() || $query->get( 'mailerhive_include_paid' ) ) {
			return;
		}
		if ( $query->get( 'p' ) || $query->get( 'name' ) || $query->get( 'post__in' ) || $query->get( 'page_id' ) ) {
			return;
		}
		$types   = (array) $query->get( 'post_type' );
		$listing = in_array( 'fluent-products', $types, true ) || in_array( 'any', $types, true )
			|| ( $query->is_main_query() && ( $query->is_search() || $query->is_tax( array( 'product-categories', 'product-brands' ) ) ) );
		if ( ! $listing ) {
			return;
		}
		$ids = self::ids();
		if ( $ids ) {
			$query->set( 'post__not_in', array_values( array_unique( array_merge( (array) $query->get( 'post__not_in' ), $ids ) ) ) );
		}
	}

	public static function rank_math_entry( $url, $type, $item ) {
		if ( 'post' === $type && is_object( $item ) && isset( $item->ID ) && self::is_hidden( (int) $item->ID ) ) {
			return false;
		}
		return $url;
	}

	private static function hidden_singular(): bool {
		return is_singular( 'fluent-products' ) && self::is_hidden( (int) get_queried_object_id() );
	}

	public static function robots( array $robots ): array {
		if ( self::hidden_singular() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	public static function rank_math_robots( $robots ) {
		if ( is_array( $robots ) && self::hidden_singular() ) {
			$robots['index'] = 'noindex';
		}
		return $robots;
	}
}
