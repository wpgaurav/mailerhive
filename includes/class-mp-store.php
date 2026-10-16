<?php
/**
 * Small MailerPress data helpers shared by modules: contacts, list membership and
 * named tags. MailerPress keeps these in custom tables without a public write API
 * for list membership, so the helpers write the same rows its own screens write.
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- MailerPress keeps its data in custom tables without a public read API; table names come from $wpdb->prefix and values are prepared.

final class MP_Store {
	public static function available(): bool {
		return class_exists( '\MailerPress\Models\Contacts' );
	}

	public static function contact( string $email ): ?object {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) || ! self::available() ) {
			return null;
		}
		return ( new \MailerPress\Models\Contacts() )->getContactByEmail( $email ) ?: null;
	}

	/**
	 * Create a subscribed contact when none exists. An existing contact is returned
	 * untouched; changing its status is a separate, explicit call.
	 */
	public static function ensure_contact( string $email, string $first = '', string $last = '', string $source = 'mailerhive' ): ?object {
		$contact = self::contact( $email );
		if ( $contact || ! class_exists( '\MailerPress\Services\ContactUpsertService' ) ) {
			return $contact;
		}
		( new \MailerPress\Services\ContactUpsertService() )->upsert(
			array(
				'email'               => sanitize_email( $email ),
				'first_name'          => $first,
				'last_name'           => $last,
				'contactStatus'       => 'subscribed',
				'opt_in_source'       => $source,
				'update_existing'     => false,
				'assign_default_list' => false,
			)
		);
		return self::contact( $email );
	}

	/**
	 * Mark a contact subscribed and merge this purchase into the existing opt-in record.
	 * Earlier consent keys are kept. Purchase history is capped so the column cannot grow without bound.
	 */
	public static function resubscribe( int $contact_id, array $evidence ): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'mailerpress_contact';
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT opt_in_details FROM {$table} WHERE contact_id = %d", $contact_id ) );
		$details = is_string( $current ) ? json_decode( $current, true ) : array();
		if ( ! is_array( $details ) ) {
			$details = array();
		}
		$history = isset( $details['mailerhive_purchases'] ) && is_array( $details['mailerhive_purchases'] ) ? $details['mailerhive_purchases'] : array();
		unset( $details['mailerhive_purchases'] );
		$history[] = $evidence;
		if ( count( $history ) > 20 ) {
			$history = array_slice( $history, -20 );
		}
		$details = array_merge( $details, $evidence );
		$details['mailerhive_purchases'] = $history;

		$wpdb->update(
			$table,
			array(
				'subscription_status' => 'subscribed',
				'last_subscribed_at'  => current_time( 'mysql', true ),
				'opt_in_details'      => wp_json_encode( $details ),
			),
			array( 'contact_id' => $contact_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function add_to_list( int $contact_id, int $list_id ): void {
		global $wpdb;
		if ( ! $contact_id || ! $list_id ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}mailerpress_contact_lists (contact_id, list_id, added_at) VALUES (%d, %d, %s)",
				$contact_id,
				$list_id,
				current_time( 'mysql', true )
			)
		);
	}

	public static function remove_from_list( int $contact_id, int $list_id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'mailerpress_contact_lists', array( 'contact_id' => $contact_id, 'list_id' => $list_id ), array( '%d', '%d' ) );
	}

	public static function list_ids_of( int $contact_id ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT list_id FROM {$wpdb->prefix}mailerpress_contact_lists WHERE contact_id = %d", $contact_id ) ) );
	}

	public static function create_list( string $name, string $description = '' ): int {
		global $wpdb;
		$name     = sanitize_text_field( $name );
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT list_id FROM {$wpdb->prefix}mailerpress_lists WHERE name = %s LIMIT 1", $name ) );
		if ( $existing ) {
			return $existing;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$wpdb->prefix . 'mailerpress_lists',
			array( 'name' => $name, 'description' => $description, 'sync' => 0, 'is_default' => 0, 'created_at' => $now, 'updated_at' => $now ),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function tag_name( string $name ): string {
		$name = sanitize_text_field( $name );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );
	}

	public static function add_tag( int $contact_id, string $name ): void {
		global $wpdb;
		$name = self::tag_name( $name );
		if ( ! $contact_id || '' === $name ) {
			return;
		}
		$table  = $wpdb->prefix . 'mailerpress_tags';
		$tag_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT tag_id FROM {$table} WHERE name = %s", $name ) );
		if ( ! $tag_id ) {
			$wpdb->insert( $table, array( 'name' => $name ), array( '%s' ) );
			$tag_id = (int) $wpdb->insert_id;
		}
		if ( $tag_id ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}mailerpress_contact_tags (contact_id, tag_id) VALUES (%d, %d)", $contact_id, $tag_id ) );
		}
	}

	public static function remove_tag( int $contact_id, string $name ): void {
		global $wpdb;
		$tag_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT tag_id FROM {$wpdb->prefix}mailerpress_tags WHERE name = %s", self::tag_name( $name ) ) );
		if ( $tag_id ) {
			$wpdb->delete( $wpdb->prefix . 'mailerpress_contact_tags', array( 'contact_id' => $contact_id, 'tag_id' => $tag_id ), array( '%d', '%d' ) );
		}
	}
}
