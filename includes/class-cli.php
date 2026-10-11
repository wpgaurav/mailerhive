<?php
/**
 * WP-CLI: wp mailerhive <command>
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- MailerPress and Core Forms keep their data in custom tables without a public read API; table names come from $wpdb->prefix and values are prepared.

final class CLI {
	/**
	 * Import Substack posts as issues.
	 *
	 * ## OPTIONS
	 *
	 * <slug>...
	 * : Substack post slugs.
	 *
	 * [--publication=<subdomain>]
	 * : Substack subdomain. Default: the publication set in Settings → Modules.
	 *
	 * [--title=<title>]
	 * : Override the title (single slug only).
	 *
	 * [--slug=<slug>]
	 * : Override the issue slug (single slug only).
	 *
	 * [--dry-run]
	 * : Fetch and convert without writing.
	 *
	 * @subcommand import-substack
	 */
	public function import_substack( $args, $assoc ) {
		$over = array();
		if ( 1 === count( $args ) ) {
			$over = array_filter( array( 'title' => $assoc['title'] ?? null, 'slug' => $assoc['slug'] ?? null ) );
		}
		foreach ( $args as $slug ) {
			$r = Importer::substack( $slug, (string) ( $assoc['publication'] ?? '' ), $over, ! empty( $assoc['dry-run'] ) );
			$r['ok'] ? \WP_CLI::log( "{$slug}: {$r['message']}" . ( $r['id'] ? " #{$r['id']} " . get_permalink( $r['id'] ) : '' ) ) : \WP_CLI::warning( "{$slug}: {$r['message']}" );
		}
		if ( empty( $assoc['dry-run'] ) ) {
			\WP_CLI::log( 'Relinked ' . Importer::relink() . ' issue(s).' );
		}
	}

	/**
	 * Rewrite links between imported Substack issues to their local issue URLs.
	 */
	public function relink() {
		\WP_CLI::success( 'Relinked ' . Importer::relink() . ' issue(s).' );
	}

	/**
	 * Publish one MailerPress campaign as an issue, regardless of its list.
	 *
	 * ## OPTIONS
	 *
	 * <campaign_id>
	 * : Campaign ID.
	 *
	 * [--title=<title>]
	 * : Issue title. Default: the campaign subject.
	 *
	 * [--slug=<slug>]
	 * : Issue slug. Default: from the title.
	 *
	 * @subcommand import-campaign
	 */
	public function import_campaign( $args, $assoc ) {
		$over = array_filter( array( 'title' => $assoc['title'] ?? null, 'slug' => $assoc['slug'] ?? null ) );
		$id   = MailerPress_Bridge::mirror( (int) $args[0], 'publish', $over );
		$id ? \WP_CLI::success( "#{$id} " . get_permalink( $id ) ) : \WP_CLI::error( 'Campaign not found or has no stored HTML.' );
	}

	/**
	 * Link workflow-drafted campaigns and mirror sent newsletters now.
	 */
	public function sync() {
		$r = MailerPress_Bridge::sync();
		\WP_CLI::success( sprintf( 'Linked %d, mirrored %d.', $r['linked'], $r['mirrored'] ) );
	}

	/**
	 * Fill blank settings from the site (Rybbit tag, Newsletter list, landing page, links).
	 */
	public function seed() {
		Settings::seed();
		\WP_CLI::log( wp_json_encode( Settings::all(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Paid subscriptions: list members, rebuild from FluentCart, grant or revoke.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : members | reconcile | sweep | grant | revoke | access
	 *
	 * [<arg>...]
	 * : reconcile [customer_id], grant <email> <tier> [YYYY-MM-DD], revoke <row_id>, access <email>
	 *
	 * [--tier=<key>]
	 * : Filter members by tier.
	 *
	 * [--all]
	 * : Include ended memberships.
	 *
	 * [--format=<format>]
	 * : table, csv or json. Default: table.
	 *
	 * @subcommand paid
	 */
	public function paid( $args, $assoc ) {
		if ( ! class_exists( '\MailerHive\Modules\Paid\Members' ) ) {
			\WP_CLI::error( 'Turn on the Paid subscriptions module first (Settings → Modules).' );
		}
		$action = array_shift( $args );
		switch ( $action ) {
			case 'members':
				global $wpdb;
				$rows = $wpdb->get_results( 'SELECT id, email, tier, mode, status, source, starts_at, expires_at FROM ' . Modules\Paid\Members::table() . ' ORDER BY updated_at DESC', ARRAY_A ) ?: array();
				$rows = array_values(
					array_filter(
						$rows,
						static function ( $r ) use ( $assoc ) {
							return ( empty( $assoc['tier'] ) || $r['tier'] === $assoc['tier'] ) && ( ! empty( $assoc['all'] ) || Modules\Paid\Members::is_live( (object) $r ) );
						}
					)
				);
				\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'email', 'tier', 'mode', 'status', 'source', 'starts_at', 'expires_at' ) );
				break;
			case 'reconcile':
				if ( $args ) {
					\WP_CLI::success( sprintf( '%d grant(s) rebuilt.', Modules\Paid\Members::reconcile_customer( (int) $args[0] ) ) );
				} else {
					\WP_CLI::success( sprintf( '%d customer(s) re-checked.', Modules\Paid\Members::reconcile_all() ) );
				}
				break;
			case 'sweep':
				$r = Modules\Paid\Members::sweep();
				\WP_CLI::success( sprintf( '%d customer(s) re-checked, %d manual grant(s) expired.', $r['customers'], $r['manual'] ) );
				break;
			case 'grant':
				if ( count( $args ) < 2 ) {
					\WP_CLI::error( 'Usage: wp mailerhive paid grant <email> <tier> [YYYY-MM-DD]' );
				}
				$until = isset( $args[2] ) && strtotime( $args[2] ) ? gmdate( 'Y-m-d 23:59:59', strtotime( $args[2] ) ) : null;
				$id    = Modules\Paid\Members::grant( $args[0], $args[1], $until, 'wp-cli' );
				$id ? \WP_CLI::success( "Granted (row {$id})." ) : \WP_CLI::error( 'Invalid email or tier.' );
				break;
			case 'revoke':
				Modules\Paid\Members::revoke( (int) ( $args[0] ?? 0 ) ) ? \WP_CLI::success( 'Revoked.' ) : \WP_CLI::error( 'Only manual grants can be revoked.' );
				break;
			case 'access':
				$rank = Modules\Paid\Members::rank_for( array( (string) ( $args[0] ?? '' ) ) );
				\WP_CLI::log( $rank ? 'Highest active tier: ' . Modules\Paid\Plans::tiers()[ $rank - 1 ]['name'] : 'No active paid access.' );
				break;
			default:
				\WP_CLI::error( 'Unknown action. Use members, reconcile, sweep, grant, revoke or access.' );
		}
	}
}
