<?php
/**
 * Paid issues in MailerPress: drafts go to the lists of the issue's tier and every
 * tier above it, and campaigns written in MailerPress for paid lists become paid
 * issues when they are mirrored.
 */

namespace MailerHive\Modules\Paid;

defined( 'ABSPATH' ) || exit;

final class Delivery {
	public static function boot(): void {
		add_filter( 'mailerhive_draft_list_ids', array( __CLASS__, 'draft_lists' ), 10, 2 );
		add_filter( 'mailerhive_mirror_list_ids', array( __CLASS__, 'mirror_lists' ) );
		add_action( 'mailerhive_issue_mirrored', array( __CLASS__, 'mark_mirrored' ), 10, 2 );
	}

	public static function draft_lists( array $lists, \WP_Post $post ): array {
		$need = Access::required( $post );
		if ( '' === $need ) {
			return $lists;
		}
		$out = array();
		foreach ( Plans::tiers_from( Plans::rank( $need ) ) as $tier ) {
			if ( $tier['list_id'] ) {
				$out[] = (int) $tier['list_id'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function mirror_lists( array $lists ): array {
		return array_values( array_unique( array_merge( $lists, Plans::tier_list_ids() ) ) );
	}

	/**
	 * A campaign that reached any free mirror list stays free. One sent only to paid
	 * lists requires the lowest tier among them, so every recipient can read it back.
	 */
	public static function mark_mirrored( int $post_id, array $campaign_lists ): void {
		$campaign_lists = array_map( 'intval', $campaign_lists );
		$free           = array_map( 'intval', (array) \MailerHive\Settings::get( 'mirror_list_ids' ) );
		if ( array_intersect( $free, $campaign_lists ) ) {
			return;
		}
		foreach ( Plans::tiers() as $tier ) {
			if ( $tier['list_id'] && in_array( (int) $tier['list_id'], $campaign_lists, true ) ) {
				update_post_meta( $post_id, Access::META, $tier['key'] );
				return;
			}
		}
	}
}
