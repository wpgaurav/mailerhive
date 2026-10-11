<?php
/**
 * Substack import and cross-issue relinking, shared by WP-CLI and the Tools tab.
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

final class Importer {
	/**
	 * @return array{ok:bool,id:int,message:string}
	 */
	public static function substack( string $slug, string $publication = '', array $overrides = array(), bool $dry = false ): array {
		$slug = sanitize_title( $slug );
		$pub  = sanitize_key( $publication ?: (string) Settings::get( 'substack_publication' ) );
		if ( '' === $pub ) {
			return array( 'ok' => false, 'id' => 0, 'message' => 'no Substack publication set' );
		}
		$res  = wp_remote_get( "https://{$pub}.substack.com/api/v1/posts/{$slug}", array( 'timeout' => 30 ) );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['body_html'] ) ) {
			return array( 'ok' => false, 'id' => 0, 'message' => 'not found on Substack' );
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
			return array( 'ok' => true, 'id' => (int) $found[0], 'message' => 'already imported' );
		}
		$content = Content::from_substack( (string) $data['body_html'] );
		$title   = (string) ( $overrides['title'] ?? $data['title'] );
		$gmt     = gmdate( 'Y-m-d H:i:s', strtotime( (string) $data['post_date'] ) );
		if ( $dry ) {
			return array( 'ok' => true, 'id' => 0, 'message' => sprintf( '"%s" %s, %d chars, %d images', $title, $gmt, strlen( $content ), substr_count( $content, '<img' ) ) );
		}
		$post_id = wp_insert_post(
			array(
				'post_type'     => Issues::TYPE,
				'post_status'   => 'draft',
				'post_title'    => $title,
				'post_name'     => sanitize_title( $overrides['slug'] ?? $slug ),
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
			return array( 'ok' => false, 'id' => 0, 'message' => $post_id->get_error_message() );
		}
		$content = Content::import_images( $content, (int) $post_id );
		wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $content, 'post_status' => 'publish' ) ) );
		return array( 'ok' => true, 'id' => (int) $post_id, 'message' => 'imported' );
	}

	/**
	 * Rewrite Substack post links inside issues to their imported local pages.
	 */
	public static function relink(): int {
		global $wpdb;
		$map  = array();
		$rows = get_posts( array( 'post_type' => Issues::TYPE, 'post_status' => array( 'publish', 'draft', 'future' ), 'posts_per_page' => -1 ) );
		$pubs = array();
		foreach ( $rows as $p ) {
			$src = (string) get_post_meta( $p->ID, Issues::META_SOURCE_URL, true );
			if ( preg_match( '~^https?://([a-z0-9-]+)\.substack\.com/p/([a-z0-9-]+)~i', $src, $m ) ) {
				$pubs[ strtolower( $m[1] ) ] = true;
				$map[ strtolower( $m[2] ) ]  = get_permalink( $p );
			}
		}
		if ( ! $map ) {
			return 0;
		}
		$hosts   = implode( '|', array_map( static fn( $p ) => preg_quote( $p, '~' ), array_keys( $pubs ) ) );
		$pattern = '~https?://(?:(?:' . $hosts . ')\.substack\.com|substack\.com/@(?:' . $hosts . '))/p/([a-z0-9-]+)[^"\'\s<]*~i';
		$changed = 0;
		foreach ( $rows as $p ) {
			$new = preg_replace_callback( $pattern, static fn( $m ) => $map[ strtolower( $m[1] ) ] ?? $m[0], $p->post_content );
			if ( is_string( $new ) && $new !== $p->post_content ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $p->ID ) );
				clean_post_cache( $p->ID );
				++$changed;
			}
		}
		return $changed;
	}
}
