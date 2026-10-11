<?php
/**
 * Paid memberships: one row per access grant, rebuilt from FluentCart.
 *
 * FluentCart stays the source of truth. Every relevant FluentCart event queues the
 * customer, and at the end of the request MailerHive recomputes that customer's rows
 * from their subscriptions and paid orders, so a missed or out-of-order webhook can
 * never leave access wrong for long. An hourly sweep does the same for grants whose
 * end date has passed. Manual grants (Newsletter → Paid members) are kept as they are.
 *
 * After every change the reader's MailerPress contact is brought in line: they sit on
 * the list of their highest active tier, carry a "Paid: {tier}" tag while it lasts,
 * and get "Paid: Lapsed" when nothing is active any more.
 */

namespace MailerHive\Modules\Paid;

use MailerHive\MP_Store;
use MailerHive\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.SchemaChange -- FluentCart and MailerPress keep their data in custom tables; table names come from $wpdb->prefix and values are prepared.

final class Members {
	const DB_VERSION = '1';
	const DB_OPTION  = 'mailerhive_paid_db';
	const SWEEP_HOOK = 'mailerhive_paid_sweep';

	/** Statuses that grant access while expires_at is empty or in the future. */
	const LIVE = array( 'active', 'canceling', 'past_due' );

	const LAPSED_TAG = 'Paid: Lapsed';

	private static $queue = array();

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mailerhive_members';
	}

	public static function boot(): void {
		self::install();

		foreach (
			array(
				'fluent_cart/order_paid_done',
				'fluent_cart/order_refunded',
				'fluent_cart/order_fully_refunded',
				'fluent_cart/order_partially_refunded',
				'fluent_cart/order_canceled',
				'fluent_cart/order_status_changed',
				'fluent_cart/order_customer_changed',
				'fluent_cart/subscription_activated',
				'fluent_cart/subscription_renewed',
				'fluent_cart/subscription_canceled',
				'fluent_cart/subscription_eot',
				'fluent_cart/subscription_expired_validity',
				'fluent_cart/subscription_paused',
				'fluent_cart/subscription_resumed',
				'fluent_cart/subscription_past_due',
				'fluent_cart/subscription_updated',
				'fluent_cart/subscription_renewal_failed',
				'fluent_cart/payments/subscription_status_changed',
			) as $hook
		) {
			add_action( $hook, array( __CLASS__, 'queue_from_event' ), 40, 1 );
		}
		add_action( 'shutdown', array( __CLASS__, 'flush_queue' ), 5 );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::SWEEP_HOOK );
		}
	}

	public static function install(): void {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(190) NOT NULL,
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				tier varchar(64) NOT NULL,
				mode varchar(20) NOT NULL,
				status varchar(20) NOT NULL,
				source varchar(64) NOT NULL,
				variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				subscription_id bigint(20) unsigned NOT NULL DEFAULT 0,
				starts_at datetime NULL DEFAULT NULL,
				expires_at datetime NULL DEFAULT NULL,
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source (source),
				KEY email (email),
				KEY customer_id (customer_id),
				KEY status_expires (status, expires_at)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/* ---------- event intake ---------- */

	public static function queue_from_event( $data ): void {
		$id = self::customer_id_from( $data );
		if ( $id ) {
			self::$queue[ $id ] = true;
		}
	}

	private static function customer_id_from( $data ): int {
		$pick = static function ( $thing, string $field ) {
			if ( is_object( $thing ) ) {
				return (int) ( $thing->{$field} ?? 0 );
			}
			return is_array( $thing ) ? (int) ( $thing[ $field ] ?? 0 ) : 0;
		};
		if ( ! is_array( $data ) ) {
			return $pick( $data, 'customer_id' );
		}
		foreach ( array( 'customer' => 'id', 'subscription' => 'customer_id', 'order' => 'customer_id', 'new_order' => 'customer_id' ) as $key => $field ) {
			$id = isset( $data[ $key ] ) ? $pick( $data[ $key ], $field ) : 0;
			if ( $id ) {
				return $id;
			}
		}
		return (int) ( $data['customer_id'] ?? 0 );
	}

	/** Runs once per request, after FluentCart has written every row. */
	public static function flush_queue(): void {
		$ids         = array_keys( self::$queue );
		self::$queue = array();
		foreach ( $ids as $id ) {
			self::reconcile_customer( (int) $id );
		}
	}

	/* ---------- reconcile ---------- */

	public static function reconcile_customer( int $customer_id ): int {
		global $wpdb;
		$plans = Plans::by_variation();
		if ( ! $customer_id || ! Plans::fluentcart_ready() ) {
			return 0;
		}
		$customer = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, email, first_name, last_name FROM {$wpdb->prefix}fct_customers WHERE id = %d", $customer_id ) );
		if ( ! $customer || ! is_email( (string) $customer->email ) ) {
			return 0;
		}
		$email = strtolower( (string) $customer->email );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$seen  = array();
		$grace = max( 0, (int) Settings::get( 'paid_grace_days' ) ) * DAY_IN_SECONDS;

		if ( $plans ) {
			$ids = implode( ',', array_map( 'intval', array_keys( $plans ) ) );

			// Subscriptions: access follows FluentCart's subscription state.
			$subs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, variation_id, parent_order_id, status, next_billing_date, expire_at, created_at
					 FROM {$wpdb->prefix}fct_subscriptions WHERE customer_id = %d AND variation_id IN ({$ids})",
					$customer_id
				)
			) ?: array();
			foreach ( $subs as $sub ) {
				$plan = $plans[ (int) $sub->variation_id ] ?? null;
				$row  = $plan ? self::subscription_row( $sub, $now, $grace ) : null;
				if ( ! $row ) {
					continue;
				}
				$source          = 'sub:' . (int) $sub->id;
				$seen[ $source ] = true;
				self::upsert(
					$source,
					$row + array(
						'email'           => $email,
						'customer_id'     => $customer_id,
						'user_id'         => (int) $customer->user_id,
						'tier'            => $plan['tier'],
						'mode'            => 'subscription',
						'variation_id'    => (int) $sub->variation_id,
						'order_id'        => (int) $sub->parent_order_id,
						'subscription_id' => (int) $sub->id,
					)
				);
			}

			// One-time purchases: fixed terms stack per tier, lifetime never ends.
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT oi.id AS item_id, oi.object_id AS variation_id, o.id AS order_id, o.payment_status, o.status,
					        COALESCE(o.completed_at, o.created_at) AS paid_at
					 FROM {$wpdb->prefix}fct_order_items oi
					 INNER JOIN {$wpdb->prefix}fct_orders o ON o.id = oi.order_id
					 WHERE o.customer_id = %d AND oi.object_id IN ({$ids}) AND o.type <> 'renewal'
					 ORDER BY paid_at ASC, oi.id ASC",
					$customer_id
				)
			) ?: array();
			$ends = array();
			foreach ( $items as $item ) {
				$plan = $plans[ (int) $item->variation_id ] ?? null;
				if ( ! $plan || 'subscription' === $plan['mode'] ) {
					continue;
				}
				$paid     = in_array( $item->payment_status, array( 'paid', 'partially_refunded' ), true ) && 'canceled' !== $item->status;
				$refunded = 'refunded' === $item->payment_status;
				if ( ! $paid && ! $refunded ) {
					continue; // pending or failed: not a grant yet
				}
				$source          = 'item:' . (int) $item->item_id;
				$seen[ $source ] = true;
				$base            = array(
					'email'           => $email,
					'customer_id'     => $customer_id,
					'user_id'         => (int) $customer->user_id,
					'tier'            => $plan['tier'],
					'mode'            => $plan['mode'],
					'variation_id'    => (int) $item->variation_id,
					'order_id'        => (int) $item->order_id,
					'subscription_id' => 0,
				);
				if ( $refunded ) {
					self::upsert( $source, $base + array( 'status' => 'refunded', 'starts_at' => (string) $item->paid_at, 'expires_at' => (string) $item->paid_at ) );
					continue;
				}
				$tier  = $plan['tier'];
				$start = (string) $item->paid_at;
				if ( isset( $ends[ $tier ] ) && null !== $ends[ $tier ] && $ends[ $tier ] > $start ) {
					$start = $ends[ $tier ]; // a renewal bought early extends the running term
				}
				if ( 'lifetime' === $plan['mode'] || ( array_key_exists( $tier, $ends ) && null === $ends[ $tier ] ) ) {
					$expires = null;
				} else {
					$expires = Plans::add_term( $start, $plan['duration'], $plan['unit'] );
				}
				$ends[ $tier ] = $expires;
				self::upsert(
					$source,
					$base + array(
						'status'     => null === $expires || $expires > $now ? 'active' : 'expired',
						'starts_at'  => $start,
						'expires_at' => $expires,
					)
				);
			}
		}

		// Rows whose FluentCart record is gone (deleted order, unmapped plan).
		$stale = $wpdb->get_col( $wpdb->prepare( "SELECT source FROM " . self::table() . " WHERE customer_id = %d AND (source LIKE 'sub:%%' OR source LIKE 'item:%%')", $customer_id ) );
		foreach ( $stale as $source ) {
			if ( ! isset( $seen[ $source ] ) ) {
				$wpdb->delete( self::table(), array( 'source' => $source ), array( '%s' ) );
			}
		}

		self::sync_contact( $email, (string) $customer->first_name, (string) $customer->last_name );
		return count( $seen );
	}

	/**
	 * @return array{status:string,starts_at:string,expires_at:?string}|null
	 */
	private static function subscription_row( object $sub, string $now, int $grace ): ?array {
		$next = $sub->next_billing_date ? (string) $sub->next_billing_date : '';
		$end  = $sub->expire_at ? (string) $sub->expire_at : $next;
		$with = static fn( string $date ) => $date ? gmdate( 'Y-m-d H:i:s', strtotime( $date . ' UTC' ) + $grace ) : null;

		switch ( (string) $sub->status ) {
			case 'active':
			case 'trialing':
				$status  = 'active';
				$expires = $with( $next );
				break;
			case 'past_due':
			case 'failing':
				$status  = 'past_due';
				$expires = $with( $next );
				break;
			case 'canceled':
			case 'expiring':
			case 'completed':
				// Paid through the current period: keep access until it ends.
				$status  = $end && $end > $now ? 'canceling' : 'canceled';
				$expires = $end ?: (string) $sub->created_at;
				break;
			case 'paused':
				$status  = 'paused';
				$expires = $next ?: null;
				break;
			case 'expired':
				$status  = 'expired';
				$expires = $end ?: (string) $sub->created_at;
				break;
			default:
				return null; // pending, intended, created, authenticated: nothing paid yet
		}
		if ( in_array( $status, self::LIVE, true ) && null !== $expires && $expires <= $now ) {
			$status = 'expired';
		}
		return array(
			'status'     => $status,
			'starts_at'  => (string) $sub->created_at,
			'expires_at' => $expires,
		);
	}

	private static function upsert( string $source, array $row ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$id  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE source = %s', $source ) );
		$row = array_merge( $row, array( 'source' => $source, 'updated_at' => $now ) );
		if ( $id ) {
			$wpdb->update( self::table(), $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( self::table(), $row + array( 'created_at' => $now ) );
		}
	}

	/* ---------- manual grants ---------- */

	public static function grant( string $email, string $tier, ?string $expires, string $note = '' ): int {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) || ! Plans::tier( $tier ) ) {
			return 0;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			self::table(),
			array(
				'email'      => $email,
				'user_id'    => (int) ( get_user_by( 'email', $email )->ID ?? 0 ),
				'tier'       => $tier,
				'mode'       => 'manual',
				'status'     => null === $expires || $expires > $now ? 'active' : 'expired',
				'source'     => 'manual:' . wp_generate_uuid4(),
				'starts_at'  => $now,
				'expires_at' => $expires,
				'note'       => sanitize_text_field( $note ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		self::sync_contact( $email );
		return $id;
	}

	public static function revoke( int $id ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		if ( ! $row || 'manual' !== $row->mode ) {
			return false;
		}
		$wpdb->update( self::table(), array( 'status' => 'revoked', 'expires_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
		self::sync_contact( (string) $row->email );
		return true;
	}

	/* ---------- access ---------- */

	/** Highest active tier rank across the given emails (0 = none). */
	public static function rank_for( array $emails ): int {
		global $wpdb;
		$emails = array_values( array_filter( array_map( static fn( $e ) => strtolower( sanitize_email( (string) $e ) ), $emails ), 'is_email' ) );
		if ( ! $emails ) {
			return 0;
		}
		$in    = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		$live  = "'" . implode( "','", self::LIVE ) . "'";
		$tiers = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT tier FROM ' . self::table() . " WHERE email IN ({$in}) AND status IN ({$live}) AND (expires_at IS NULL OR expires_at > %s)",
				array_merge( $emails, array( gmdate( 'Y-m-d H:i:s' ) ) )
			)
		);
		$rank = 0;
		foreach ( $tiers as $tier ) {
			$rank = max( $rank, Plans::rank( (string) $tier ) );
		}
		return $rank;
	}

	public static function rows_for( string $email ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s ORDER BY (expires_at IS NULL) DESC, expires_at DESC', strtolower( $email ) ) ) ?: array();
	}

	public static function is_live( object $row ): bool {
		return in_array( $row->status, self::LIVE, true ) && ( null === $row->expires_at || $row->expires_at > gmdate( 'Y-m-d H:i:s' ) );
	}

	/* ---------- MailerPress contact ---------- */

	public static function sync_contact( string $email, string $first = '', string $last = '' ): void {
		if ( ! MP_Store::available() ) {
			return;
		}
		$email = strtolower( $email );
		$rank  = self::rank_for( array( $email ) );
		$tiers = Plans::tiers();
		$top   = $rank ? $tiers[ $rank - 1 ] : null;

		$contact = MP_Store::contact( $email );
		if ( ! $contact ) {
			if ( ! $top ) {
				return;
			}
			$contact = MP_Store::ensure_contact( $email, $first, $last, 'mailerhive_paid' );
			if ( ! $contact ) {
				return;
			}
		} elseif ( $top && Settings::on( 'paid_resubscribe' ) && in_array( (string) $contact->subscription_status, array( 'unsubscribed', 'inactive', 'pending' ), true ) ) {
			// Buying the paid newsletter is a direct request to receive it.
			MP_Store::resubscribe(
				(int) $contact->contact_id,
				array(
					'source'              => 'mailerhive_paid',
					'consent_recorded_at' => gmdate( 'Y-m-d H:i:s' ),
					'tier'                => $top['key'],
					'previous_status'     => (string) $contact->subscription_status,
				)
			);
		}
		$cid = (int) $contact->contact_id;

		foreach ( $tiers as $tier ) {
			$list = (int) $tier['list_id'];
			$tag  = sprintf( 'Paid: %s', $tier['name'] );
			if ( $top && $tier['key'] === $top['key'] ) {
				MP_Store::add_to_list( $cid, $list );
				MP_Store::add_tag( $cid, $tag );
			} else {
				if ( $list ) {
					MP_Store::remove_from_list( $cid, $list );
				}
				MP_Store::remove_tag( $cid, $tag );
			}
		}

		if ( $top ) {
			MP_Store::remove_tag( $cid, self::LAPSED_TAG );
			if ( Settings::on( 'paid_add_free_list' ) ) {
				foreach ( \MailerHive\Email::list_ids() as $free ) {
					MP_Store::add_to_list( $cid, (int) $free );
				}
			}
		} elseif ( self::rows_for( $email ) ) {
			MP_Store::add_tag( $cid, self::LAPSED_TAG );
		}
	}

	/* ---------- housekeeping ---------- */

	/** Re-check grants whose end date has passed, and expire manual ones. */
	public static function sweep(): array {
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$live = "'" . implode( "','", self::LIVE ) . "'";
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, email, customer_id, mode FROM ' . self::table() . " WHERE status IN ({$live}) AND expires_at IS NOT NULL AND expires_at <= %s", $now ) ) ?: array();
		$done = array( 'customers' => 0, 'manual' => 0 );
		$cust = array();
		foreach ( $rows as $row ) {
			if ( 'manual' === $row->mode ) {
				$wpdb->update( self::table(), array( 'status' => 'expired', 'updated_at' => $now ), array( 'id' => (int) $row->id ) );
				self::sync_contact( (string) $row->email );
				++$done['manual'];
			} elseif ( $row->customer_id ) {
				$cust[ (int) $row->customer_id ] = true;
			}
		}
		foreach ( array_keys( $cust ) as $id ) {
			self::reconcile_customer( $id );
			++$done['customers'];
		}
		return $done;
	}

	/** Rebuild every customer who ever bought a mapped plan. */
	public static function reconcile_all(): int {
		global $wpdb;
		$plans = Plans::by_variation();
		if ( ! $plans || ! Plans::fluentcart_ready() ) {
			return 0;
		}
		$ids = implode( ',', array_map( 'intval', array_keys( $plans ) ) );
		$customers = $wpdb->get_col(
			"SELECT DISTINCT o.customer_id FROM {$wpdb->prefix}fct_order_items oi
			 INNER JOIN {$wpdb->prefix}fct_orders o ON o.id = oi.order_id WHERE oi.object_id IN ({$ids})
			 UNION SELECT DISTINCT customer_id FROM {$wpdb->prefix}fct_subscriptions WHERE variation_id IN ({$ids})"
		);
		$known = $wpdb->get_col( 'SELECT DISTINCT customer_id FROM ' . self::table() . ' WHERE customer_id > 0' );
		$all   = array_unique( array_map( 'intval', array_merge( $customers, $known ) ) );
		foreach ( $all as $id ) {
			self::reconcile_customer( $id );
		}
		return count( $all );
	}

	public static function counts(): array {
		global $wpdb;
		$live = "'" . implode( "','", self::LIVE ) . "'";
		$out  = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT tier, COUNT(DISTINCT email) AS n FROM ' . self::table() . " WHERE status IN ({$live}) AND (expires_at IS NULL OR expires_at > %s) GROUP BY tier", gmdate( 'Y-m-d H:i:s' ) ) ) ?: array() as $r ) {
			$out[ (string) $r->tier ] = (int) $r->n;
		}
		return $out;
	}
}
