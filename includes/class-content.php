<?php
/**
 * Content conversion for imported issues: Substack post HTML and MailerPress email
 * HTML become clean, theme-independent issue content. Remote images are sideloaded
 * into the media library so issue pages never hotlink.
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

final class Content {
	const KEEP_ATTRS = array(
		'a'          => array( 'href', 'title', 'id' ),
		'img'        => array( 'src', 'alt', 'width', 'height' ),
		'ol'         => array( 'start' ),
		'td'         => array( 'colspan', 'rowspan' ),
		'th'         => array( 'colspan', 'rowspan', 'scope' ),
		'code'       => array( 'class' ),
		'sup'        => array( 'id' ),
		'div'        => array( 'id', 'class' ),
		'p'          => array( 'id' ),
		'li'         => array( 'id' ),
		'h2'         => array( 'id' ),
		'h3'         => array( 'id' ),
	);

	private static function dom( string $html ): \DOMDocument {
		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="mh-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		return $doc;
	}

	private static function inner( \DOMDocument $doc ): string {
		$root = $doc->getElementById( 'mh-root' );
		$out  = '';
		foreach ( $root ? $root->childNodes : array() as $child ) {
			$out .= $doc->saveHTML( $child );
		}
		return trim( $out );
	}

	private static function remove( \DOMNode $node ): void {
		if ( $node->parentNode ) {
			$node->parentNode->removeChild( $node );
		}
	}

	private static function unwrap( \DOMNode $node ): void {
		$parent = $node->parentNode;
		if ( ! $parent ) {
			return;
		}
		while ( $node->firstChild ) {
			$parent->insertBefore( $node->firstChild, $node );
		}
		$parent->removeChild( $node );
	}

	private static function strip_attributes( \DOMDocument $doc ): void {
		$xp = new \DOMXPath( $doc );
		foreach ( iterator_to_array( $xp->query( '//*[@*]' ) ) as $el ) {
			$keep = self::KEEP_ATTRS[ strtolower( $el->nodeName ) ] ?? array();
			foreach ( iterator_to_array( $el->attributes ) as $attr ) {
				if ( 'mh-root' === $el->getAttribute( 'id' ) ) {
					continue 2;
				}
				if ( ! in_array( strtolower( $attr->name ), $keep, true ) ) {
					$el->removeAttribute( $attr->name );
				}
			}
			if ( 'div' === strtolower( $el->nodeName ) && $el->hasAttribute( 'class' ) && ! preg_match( '/\bfootnote\b/', $el->getAttribute( 'class' ) ) ) {
				$el->removeAttribute( 'class' );
			}
		}
	}

	private static function drop_empty( \DOMDocument $doc ): void {
		$xp = new \DOMXPath( $doc );
		foreach ( iterator_to_array( $xp->query( '//p|//div|//span|//h2|//h3|//h4|//li' ) ) as $el ) {
			$text = trim( str_replace( array( "\xC2\xA0", "\xE2\x80\x8B" ), '', $el->textContent ) );
			if ( '' === $text && 0 === $xp->query( './/img|.//hr|.//br[following-sibling::*]', $el )->length && 'mh-root' !== $el->getAttribute( 'id' ) ) {
				self::remove( $el );
			}
		}
	}

	/**
	 * Substack post body to issue content.
	 */
	public static function from_substack( string $html ): string {
		$doc = self::dom( $html );
		$xp  = new \DOMXPath( $doc );

		// Subscribe/share widgets and buttons that only make sense on Substack.
		$junk = '//*[contains(concat(" ",normalize-space(@class)," ")," subscription-widget-wrap ")'
			. ' or contains(concat(" ",normalize-space(@class)," ")," subscription-widget-wrap-editor ")'
			. ' or contains(concat(" ",normalize-space(@class)," ")," button-wrapper ")'
			. ' or contains(concat(" ",normalize-space(@class)," ")," captioned-button-wrap ")'
			. ' or contains(concat(" ",normalize-space(@class)," ")," image-link-expand ")'
			. ' or contains(concat(" ",normalize-space(@class)," ")," embedded-post-wrap ")'
			. ' or self::script or self::style or self::form or self::svg or self::button]';
		foreach ( iterator_to_array( $xp->query( $junk ) ) as $n ) {
			self::remove( $n );
		}

		// Images: keep a plain <img> plus caption inside a <figure>. libxml's HTML4 parser
		// treats <source> as a container, so <img> ends up inside it: unwrap, never remove.
		foreach ( iterator_to_array( $xp->query( '//source' ) ) as $source ) {
			self::unwrap( $source );
		}
		foreach ( iterator_to_array( $xp->query( '//picture' ) ) as $pic ) {
			self::unwrap( $pic );
		}
		foreach ( iterator_to_array( $xp->query( '//a[contains(@class,"image-link")]' ) ) as $a ) {
			self::unwrap( $a );
		}
		foreach ( iterator_to_array( $xp->query( '//*[contains(@class,"image2-inset") or contains(@class,"captioned-image-container")]' ) ) as $wrap ) {
			self::unwrap( $wrap );
		}
		foreach ( iterator_to_array( $xp->query( '//img' ) ) as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( '' === $src && $img->hasAttribute( 'data-src' ) ) {
				$img->setAttribute( 'src', $img->getAttribute( 'data-src' ) );
			}
			if ( preg_match( '#/(https%3A%2F%2F[^/?]+)#', $img->getAttribute( 'src' ), $m ) ) {
				$img->setAttribute( 'src', rawurldecode( $m[1] ) ); // substackcdn fetch wrapper -> original asset
			}
			foreach ( array( 'width', 'height' ) as $dim ) {
				$v = (float) $img->getAttribute( $dim );
				if ( $v <= 0 || $v > 2400 ) {
					$img->removeAttribute( $dim );
				} else {
					$img->setAttribute( $dim, (string) (int) round( $v ) );
				}
			}
			$attrs = json_decode( html_entity_decode( (string) $img->getAttribute( 'data-attrs' ) ), true );
			if ( ! $img->getAttribute( 'alt' ) && ! empty( $attrs['alt'] ) ) {
				$img->setAttribute( 'alt', (string) $attrs['alt'] );
			}
		}

		// Footnote numbers render as superscript links in the source.
		foreach ( iterator_to_array( $xp->query( '//a[contains(@class,"footnote-anchor")]' ) ) as $a ) {
			$sup = $doc->createElement( 'sup' );
			$a->parentNode->insertBefore( $sup, $a );
			$sup->appendChild( $a );
		}

		self::strip_attributes( $doc );
		self::drop_empty( $doc );
		return self::inner( $doc );
	}

	/**
	 * MailerPress email HTML (table layout) to issue content. Recipient-only parts
	 * (unsubscribe/manage links and the "why you got this" footer) are dropped.
	 */
	public static function from_email( string $html ): string {
		if ( preg_match( '#<body[^>]*>(.*)</body>#is', $html, $m ) ) {
			$html = $m[1];
		}
		$html = preg_replace( '#<(style|script|head)\b.*?</\1>#is', '', $html );
		$html = preg_replace( '/%%MP_COND_START%%.+?%%MP_COND_START%%(.*?)%%MP_COND_END%%/s', '$1', $html );
		$doc  = self::dom( $html );
		$xp   = new \DOMXPath( $doc );

		foreach ( iterator_to_array( $xp->query( '//a[contains(@href,"UNSUB_LINK") or contains(@href,"MANAGE_SUB_LINK")]' ) ) as $a ) {
			$block = $a;
			while ( $block->parentNode && ! in_array( strtolower( $block->parentNode->nodeName ), array( 'td', 'div', 'p' ), true ) ) {
				$block = $block->parentNode;
			}
			self::remove( $block->parentNode ?: $block );
		}
		foreach ( iterator_to_array( $xp->query( '//p|//td|//div' ) ) as $el ) {
			if ( preg_match( '/^\s*This email was sent to you\b/i', $el->textContent ) ) {
				self::remove( $el );
			}
		}
		foreach ( iterator_to_array( $xp->query( '//span[contains(@class,"merge-tag-span")]' ) ) as $n ) {
			self::remove( $n );
		}
		foreach ( array( 'table', 'tbody', 'thead', 'tr', 'td', 'th', 'center', 'font', 'span' ) as $tag ) {
			foreach ( iterator_to_array( $doc->getElementsByTagName( $tag ) ) as $n ) {
				if ( in_array( $tag, array( 'td', 'th' ), true ) && 0 === $xp->query( './/p|.//h1|.//h2|.//h3|.//ul|.//ol|.//img|.//div', $n )->length && '' !== trim( $n->textContent ) ) {
					$p = $doc->createElement( 'p' );
					while ( $n->firstChild ) {
						$p->appendChild( $n->firstChild );
					}
					$n->appendChild( $p );
				}
				self::unwrap( $n );
			}
		}
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'h1' ) ) as $h ) {
			$h2 = $doc->createElement( 'h2' );
			while ( $h->firstChild ) {
				$h2->appendChild( $h->firstChild );
			}
			$h->parentNode->replaceChild( $h2, $h );
		}
		self::strip_attributes( $doc );
		foreach ( iterator_to_array( $xp->query( '//div[not(@id="mh-root")]' ) ) as $div ) {
			self::unwrap( $div );
		}
		self::drop_empty( $doc );
		$out = self::inner( $doc );
		$out = preg_replace( '/\{\{[a-z_]+\}\}|%[A-Z_]+%/', '', $out );
		return str_replace( "\xE2\x80\x8B", '', $out );
	}

	/**
	 * Sideload every non-internal image into the media library and point the content at it.
	 */
	public static function import_images( string $html, int $post_id ): string {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$internal = Settings::internal_hosts();
		$done     = array();
		$first    = 0;
		$html     = preg_replace_callback(
			'#<img\b[^>]*\bsrc="([^"]+)"[^>]*>#i',
			static function ( $m ) use ( $post_id, $internal, &$done, &$first ) {
				$src  = html_entity_decode( $m[1] );
				$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
				foreach ( $internal as $h ) {
					if ( $host === $h || str_ends_with( $host, '.' . $h ) ) {
						return $m[0];
					}
				}
				if ( ! isset( $done[ $src ] ) ) {
					$tmp = download_url( $src, 60 );
					if ( is_wp_error( $tmp ) ) {
						return $m[0];
					}
					$path = (string) wp_parse_url( $src, PHP_URL_PATH );
					$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
					$ext  = in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ? $ext : 'jpg';
					$name = sanitize_file_name( 'mailerhive-issue-' . $post_id . '-' . substr( md5( $src ), 0, 8 ) . '.' . $ext );
					$id   = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id );
					if ( is_wp_error( $id ) ) {
						wp_delete_file( $tmp );
						return $m[0];
					}
					$done[ $src ] = (int) $id;
					$first        = $first ?: (int) $id;
				}
				$url = wp_get_attachment_url( $done[ $src ] );
				return str_replace( $m[1], esc_url( $url ), $m[0] );
			},
			$html
		);
		if ( $first && ! has_post_thumbnail( $post_id ) ) {
			set_post_thumbnail( $post_id, $first );
		}
		return $html;
	}
}
