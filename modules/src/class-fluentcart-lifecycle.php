<?php
/**
 * FluentCart → MailerPress lifecycle classification: purchase, refund and
 * abandoned-checkout tags plus purchase custom fields. A paid order may create a
 * contact on the customer list; it never changes an existing contact's status, so
 * a previous unsubscribe is never reversed by a purchase.
 */

namespace MailerHive\Modules;

defined( 'ABSPATH' ) || exit;


final class FluentCart_Lifecycle {
	private const CONSENT_VERSION = 'newsletter-consent-v1';
	private const ABANDONED_TAG = 'Abandoned';
	private const SWEEP_HOOK = 'mailerhive_fc_abandoned_sweep';
	private const LEGACY_SWEEP_HOOK = 'gt_mp_abandoned_sweep';

	/**
	 * How long an unpaid checkout must sit before it counts as abandoned. Tagging on
	 * order creation would label every shopper mid-checkout and untag them seconds
	 * later, which is harmless as a record but unsafe to trigger an automation from.
	 */
	private const ABANDONED_AFTER_HOURS = 24;

	public static function boot(): void {
		add_action( 'fluent_cart/order_created', array( __CLASS__, 'record_checkout_consent' ), 30, 1 );
		add_action( 'fluent_cart/order_paid_done', array( __CLASS__, 'classify_paid_order' ), 30, 1 );
		add_action( 'fluent_cart/order_refunded', array( __CLASS__, 'classify_refund' ), 30, 1 );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'run_abandoned_sweep' ) );

		if ( wp_next_scheduled( self::LEGACY_SWEEP_HOOK ) ) {
			wp_clear_scheduled_hook( self::LEGACY_SWEEP_HOOK );
		}
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK );
		}
	}

	public static function record_checkout_consent( $data ): void {
		// FluentCart validates the checkout request before this action runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['mailerpress_subscribe'] ) ) {
			return;
		}

		$contact = self::contact_from_event( $data );
		if ( ! $contact ) {
			return;
		}

		$recorded_at = current_time( 'mysql', true );
		self::set_custom_field( (int) $contact->contact_id, 'acquisition_source', 'fluentcart_checkout' );
		self::set_custom_field( (int) $contact->contact_id, 'consent_form', 'fluentcart_checkout' );
		self::set_custom_field( (int) $contact->contact_id, 'consent_recorded_at', gmdate( 'Y-m-d', strtotime( $recorded_at ) ) );
		self::set_custom_field( (int) $contact->contact_id, 'consent_version', self::CONSENT_VERSION );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'mailerpress_contact',
			array(
				'opt_in_details' => wp_json_encode(
					array(
						'source'              => 'fluentcart_checkout',
						'consent_recorded_at' => $recorded_at,
						'consent_version'     => self::CONSENT_VERSION,
					)
				),
			),
			array( 'contact_id' => (int) $contact->contact_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public static function classify_paid_order( $data ): void {
		$contact = self::ensure_contact_from_event( $data );
		$order   = is_array( $data ) ? ( $data['order'] ?? null ) : null;

		if ( ! $contact || ! $order ) {
			return;
		}

		$order_id = (int) ( is_object( $order ) ? ( $order->id ?? 0 ) : ( $order['id'] ?? 0 ) );
		if ( ! $order_id ) {
			return;
		}

		$contact_id = (int) $contact->contact_id;
		self::add_named_tag( $contact_id, 'Source: FluentCart Purchase' );
		self::add_named_tag( $contact_id, 'Customer: Paid' );
		// They converted, so the abandoned record no longer describes them.
		self::remove_named_tag( $contact_id, self::ABANDONED_TAG );

		$paid_at = is_object( $order )
			? ( $order->completed_at ?? $order->created_at ?? '' )
			: ( $order['completed_at'] ?? $order['created_at'] ?? '' );

		if ( $paid_at ) {
			self::set_custom_field_if_empty( $contact_id, 'fluentcart_customer_since', gmdate( 'Y-m-d', strtotime( (string) $paid_at ) ) );
			self::set_custom_field( $contact_id, 'last_purchase_at', gmdate( 'Y-m-d', strtotime( (string) $paid_at ) ) );
		}

		foreach ( self::order_items( $order_id ) as $item ) {
			$title = sanitize_text_field( (string) $item['post_title'] );
			if ( '' !== $title ) {
				self::add_named_tag( $contact_id, self::bounded_tag_name( 'Purchased: ' . $title ) );
				self::set_custom_field( $contact_id, 'last_purchased_product', $title );
			}

			$product_id = (int) $item['post_id'];
			if ( $product_id ) {
				foreach ( self::category_tags() as $slug => $tag ) {
					if ( has_term( $slug, 'product-categories', $product_id ) ) {
						self::add_named_tag( $contact_id, $tag );
					}
				}
			}
		}
	}

	/** "category-slug | Tag name" lines from Settings → Modules. */
	private static function category_tags(): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) \MailerHive\Settings::get( 'fc_category_tags' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$out[ sanitize_title( $parts[0] ) ] = sanitize_text_field( $parts[1] );
			}
		}
		return $out;
	}

	public static function classify_refund( $data ): void {
		$contact = self::contact_from_event( $data );
		if ( $contact ) {
			self::add_named_tag( (int) $contact->contact_id, 'Customer: Refunded' );
		}
	}

	/**
	 * Resolve the contact for a paid order, creating one when it does not exist.
	 *
	 * Contacts are created from paid orders so every paying customer can receive
	 * critical product updates. Creation is deliberately
	 * limited to this paid path, and an existing contact is never modified here, so a
	 * previous unsubscribe can never be reversed by a purchase.
	 */
	private static function ensure_contact_from_event( $data ): ?object {
		$contact = self::contact_from_event( $data );
		if ( $contact ) {
			return $contact;
		}

		$email = self::email_from_event( $data );
		if ( '' === $email || ! class_exists( '\\MailerPress\\Services\\ContactUpsertService' ) ) {
			return null;
		}

		$customer = is_array( $data ) ? ( $data['customer'] ?? null ) : null;
		$payload  = array(
			'email'               => $email,
			'first_name'          => (string) ( is_object( $customer ) ? ( $customer->first_name ?? '' ) : '' ),
			'last_name'           => (string) ( is_object( $customer ) ? ( $customer->last_name ?? '' ) : '' ),
			'contactStatus'       => 'subscribed',
			'opt_in_source'       => 'fluentcart_purchase',
			'update_existing'     => false,
			'assign_default_list' => false,
		);

		$list_id = self::list_id_by_name( (string) ( \MailerHive\Settings::get( 'fc_customer_list' ) ?: 'Customers' ) );
		if ( $list_id ) {
			$payload['lists'] = array( array( 'id' => $list_id ) );
		}

		( new \MailerPress\Services\ContactUpsertService() )->upsert( $payload );

		return self::contact_from_event( $data );
	}

	/**
	 * Tag customers whose checkouts never completed, and only after they have had time
	 * to complete. Runs daily; safe to run by hand at any time.
	 */
	public static function run_abandoned_sweep(): void {
		global $wpdb;

		if ( ! class_exists( '\\MailerPress\\Models\\Contacts' ) ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::ABANDONED_AFTER_HOURS * HOUR_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.email, c.first_name, c.last_name
				 FROM {$wpdb->prefix}fct_customers c
				 INNER JOIN {$wpdb->prefix}fct_orders o ON o.customer_id = c.id
				 WHERE c.email <> ''
				 GROUP BY c.id, c.email, c.first_name, c.last_name
				 HAVING SUM( o.status = 'completed' ) = 0
				    AND SUM( o.status = 'on-hold' ) > 0
				    AND MIN( o.created_at ) <= %s",
				$cutoff
			),
			ARRAY_A
		) ?: array();

		foreach ( $rows as $row ) {
			self::record_abandoned( (string) $row['email'], (string) $row['first_name'], (string) $row['last_name'] );
		}
	}

	/**
	 * An abandoned checkout is a business record, not a mailing permission, so the
	 * contact is stored unsubscribed and on no list. It can never receive email until
	 * the person subscribes through a real opt-in.
	 */
	private static function record_abandoned( string $email, string $first, string $last ): void {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return;
		}

		$model   = new \MailerPress\Models\Contacts();
		$contact = $model->getContactByEmail( $email );

		if ( ! $contact ) {
			if ( ! class_exists( '\\MailerPress\\Services\\ContactUpsertService' ) ) {
				return;
			}

			( new \MailerPress\Services\ContactUpsertService() )->upsert(
				array(
					'email'               => $email,
					'first_name'          => $first,
					'last_name'           => $last,
					'contactStatus'       => 'unsubscribed',
					'opt_in_source'       => 'fluentcart_abandoned',
					'update_existing'     => false,
					'assign_default_list' => false,
				)
			);

			$contact = $model->getContactByEmail( $email );
		}

		// An existing contact only gains the tag. Status and lists are left untouched.
		if ( $contact ) {
			self::add_named_tag( (int) $contact->contact_id, self::ABANDONED_TAG );
		}
	}

	private static function list_id_by_name( string $name ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT list_id FROM {$wpdb->prefix}mailerpress_lists WHERE name = %s LIMIT 1", $name )
		);
	}

	private static function email_from_event( $data ): string {
		$customer = is_array( $data ) ? ( $data['customer'] ?? null ) : null;
		$email    = '';

		if ( is_object( $customer ) ) {
			$email = $customer->email ?? '';
		} elseif ( is_array( $customer ) ) {
			$email = $customer['email'] ?? '';
		}

		$email = sanitize_email( (string) $email );

		return is_email( $email ) ? $email : '';
	}

	private static function remove_named_tag( int $contact_id, string $name ): void {
		global $wpdb;

		$tag_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT tag_id FROM {$wpdb->prefix}mailerpress_tags WHERE name = %s", self::bounded_tag_name( $name ) )
		);

		if ( $tag_id ) {
			$wpdb->delete(
				$wpdb->prefix . 'mailerpress_contact_tags',
				array( 'contact_id' => $contact_id, 'tag_id' => $tag_id ),
				array( '%d', '%d' )
			);
		}
	}

	private static function contact_from_event( $data ): ?object {
		if ( ! class_exists( '\\MailerPress\\Models\\Contacts' ) ) {
			return null;
		}

		$customer = is_array( $data ) ? ( $data['customer'] ?? null ) : null;
		$email    = '';

		if ( is_object( $customer ) ) {
			$email = $customer->email ?? '';
		} elseif ( is_array( $customer ) ) {
			$email = $customer['email'] ?? '';
		}

		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return null;
		}

		return ( new \MailerPress\Models\Contacts() )->getContactByEmail( $email ) ?: null;
	}

	private static function order_items( int $order_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_title FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d ORDER BY id ASC",
				$order_id
			),
			ARRAY_A
		) ?: array();
	}

	private static function add_named_tag( int $contact_id, string $name ): void {
		global $wpdb;

		$name = self::bounded_tag_name( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return;
		}

		$tags_table = $wpdb->prefix . 'mailerpress_tags';
		$tag_id     = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT tag_id FROM {$tags_table} WHERE name = %s", $name )
		);

		if ( ! $tag_id ) {
			$wpdb->insert( $tags_table, array( 'name' => $name ), array( '%s' ) );
			$tag_id = (int) $wpdb->insert_id;
		}

		if ( $tag_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}mailerpress_contact_tags (contact_id, tag_id) VALUES (%d, %d)",
					$contact_id,
					$tag_id
				)
			);
		}
	}

	private static function set_custom_field_if_empty( int $contact_id, string $field_key, string $value ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mailerpress_contact_custom_fields';
		$has_value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT field_value FROM {$table} WHERE contact_id = %d AND field_key = %s AND field_value <> '' LIMIT 1",
				$contact_id,
				$field_key
			)
		);

		if ( null === $has_value || '' === $has_value ) {
			self::set_custom_field( $contact_id, $field_key, $value );
		}
	}

	private static function set_custom_field( int $contact_id, string $field_key, string $value ): void {
		global $wpdb;
		$table    = $wpdb->prefix . 'mailerpress_contact_custom_fields';
		$field_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT field_id FROM {$table} WHERE contact_id = %d AND field_key = %s ORDER BY field_id ASC LIMIT 1",
				$contact_id,
				$field_key
			)
		);

		if ( $field_id ) {
			$wpdb->update(
				$table,
				array( 'field_value' => $value, 'updated_at' => current_time( 'mysql' ) ),
				array( 'field_id' => $field_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'contact_id'  => $contact_id,
				'field_key'   => $field_key,
				'field_value' => $value,
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	private static function bounded_tag_name( string $name ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );
	}
}

FluentCart_Lifecycle::boot();
