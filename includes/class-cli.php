<?php
/**
 * WP-CLI: wp mailerhive <command>
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

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
	 * : Substack subdomain.
	 * ---
	 * default: gauravtiwari
	 * ---
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
			$r = Importer::substack( $slug, (string) $assoc['publication'], $over, ! empty( $assoc['dry-run'] ) );
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
}
