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
	 * : Substack subdomain. Default: gauravtiwari
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
		$pub = sanitize_key( $assoc['publication'] ?? 'gauravtiwari' );
		$dry = ! empty( $assoc['dry-run'] );
		foreach ( $args as $slug ) {
			$slug = sanitize_title( $slug );
			$res  = wp_remote_get( "https://{$pub}.substack.com/api/v1/posts/{$slug}", array( 'timeout' => 30 ) );
			$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( empty( $data['body_html'] ) ) {
				\WP_CLI::warning( "{$slug}: not found or empty" );
				continue;
			}
			$canonical = (string) $data['canonical_url'];
			$found     = get_posts(
				array(
					'post_type'      => Issues::TYPE,
					'post_status'    => 'any',
					'meta_key'       => Issues::META_SOURCE_URL, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $canonical,             // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			if ( $found ) {
				\WP_CLI::log( "{$slug}: already imported as #{$found[0]}" );
				continue;
			}
			$content = Content::from_substack( (string) $data['body_html'] );
			$title   = ( 1 === count( $args ) && ! empty( $assoc['title'] ) ) ? (string) $assoc['title'] : (string) $data['title'];
			$gmt     = gmdate( 'Y-m-d H:i:s', strtotime( (string) $data['post_date'] ) );
			if ( $dry ) {
				\WP_CLI::log( sprintf( '%s: "%s" %s, %d chars, %d images', $slug, $title, $gmt, strlen( $content ), substr_count( $content, '<img' ) ) );
				continue;
			}
			$post_id = wp_insert_post(
				array(
					'post_type'     => Issues::TYPE,
					'post_status'   => 'draft',
					'post_title'    => $title,
					'post_name'     => ( 1 === count( $args ) && ! empty( $assoc['slug'] ) ) ? sanitize_title( $assoc['slug'] ) : $slug,
					'post_excerpt'  => (string) ( $data['subtitle'] ?? '' ),
					'post_date'     => get_date_from_gmt( $gmt ),
					'post_date_gmt' => $gmt,
					'meta_input'    => array(
						Issues::META_SOURCE     => 'substack',
						Issues::META_SOURCE_URL => $canonical,
						Issues::META_SUBTITLE   => (string) ( $data['subtitle'] ?? '' ),
					),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::warning( "{$slug}: " . $post_id->get_error_message() );
				continue;
			}
			$content = Content::import_images( $content, (int) $post_id );
			wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $content, 'post_status' => 'publish' ) ) );
			\WP_CLI::success( "{$slug}: #{$post_id} " . get_permalink( $post_id ) );
		}
	}

	/**
	 * Rewrite links between imported Substack issues to their local issue URLs.
	 *
	 * [--publication=<subdomain>]
	 * : Substack subdomain. Default: gauravtiwari
	 *
	 * @subcommand relink
	 */
	public function relink( $args, $assoc ) {
		global $wpdb;
		$pub  = sanitize_key( $assoc['publication'] ?? 'gauravtiwari' );
		$map  = array();
		$rows = get_posts( array( 'post_type' => Issues::TYPE, 'post_status' => 'publish', 'posts_per_page' => -1 ) );
		foreach ( $rows as $p ) {
			$src = (string) get_post_meta( $p->ID, Issues::META_SOURCE_URL, true );
			if ( preg_match( '#/p/([^/?#]+)#', $src, $m ) ) {
				$map[ $m[1] ] = get_permalink( $p );
			}
		}
		$changed = 0;
		foreach ( $rows as $p ) {
			$new = preg_replace_callback(
				'#https?://(?:' . preg_quote( $pub, '#' ) . '\.substack\.com|substack\.com/@' . preg_quote( $pub, '#' ) . ')/p/([a-z0-9-]+)[^"\'\s<]*#i',
				static fn( $m ) => $map[ $m[1] ] ?? $m[0],
				$p->post_content
			);
			if ( $new !== $p->post_content ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $p->ID ) );
				clean_post_cache( $p->ID );
				++$changed;
			}
		}
		\WP_CLI::success( "Relinked {$changed} issues (" . count( $map ) . ' known Substack slugs).' );
	}

	/**
	 * Publish one MailerPress campaign as an issue, regardless of its list.
	 *
	 * <campaign_id>
	 * : Campaign ID.
	 *
	 * [--title=<title>]
	 * [--slug=<slug>]
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
	 * Fill blank settings from the site (Rybbit tag, Newsletter list, landing page).
	 */
	public function seed() {
		Settings::seed();
		\WP_CLI::log( wp_json_encode( Settings::all(), JSON_PRETTY_PRINT ) );
	}
}
