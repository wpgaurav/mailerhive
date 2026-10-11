<?php
/**
 * The MailerHive reading shell. Issue pages, the issue archive and (optionally)
 * MailerPress subscription pages render through MailerHive's own templates with a
 * dedicated header and footer. With theme isolation on, everything the active theme
 * hooks into the document (styles, scripts, head/footer output, content filters) is
 * detached for these requests; WordPress core output and every plugin keep working,
 * including Functionalities fonts, Rank Math, Core Forms and Perfmatters. Rybbit is
 * printed here because the site's tag normally comes from the theme's footer scripts.
 */

namespace MailerHive;

defined( 'ABSPATH' ) || exit;

final class Shell {
	private static $context = null;

	/** Hooks whose theme callbacks are detached on shell requests. */
	const THEME_HOOKS = array(
		'wp_head',
		'wp_body_open',
		'wp_footer',
		'wp_enqueue_scripts',
		'wp_print_styles',
		'wp_print_scripts',
		'wp_print_footer_scripts',
		'the_content',
		'body_class',
		'wp_resource_hints',
		'script_loader_tag',
		'style_loader_tag',
	);

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'prepare' ), 0 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
	}

	/**
	 * issue | archive | mailerpress | null
	 */
	public static function context(): ?string {
		if ( null !== self::$context ) {
			return self::$context ?: null;
		}
		$ctx = '';
		if ( is_singular( Issues::TYPE ) ) {
			$ctx = 'issue';
		} elseif ( is_post_type_archive( Issues::TYPE ) ) {
			$ctx = 'archive';
		} elseif ( Settings::on( 'shell_mailerpress_pages' ) && self::is_mailerpress_page() ) {
			$ctx = 'mailerpress';
		}
		self::$context = $ctx;
		return $ctx ?: null;
	}

	private static function is_mailerpress_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		$cpt = '';
		if ( class_exists( '\MailerPress\Core\Kernel' ) ) {
			try {
				$cpt = (string) \MailerPress\Core\Kernel::getContainer()->get( 'cpt-page-slug' );
			} catch ( \Throwable $e ) {
				$cpt = '';
			}
		}
		return ( $cpt && $post->post_type === $cpt ) || has_shortcode( (string) $post->post_content, 'mailerpress_pages' );
	}

	public static function prepare(): void {
		if ( ! self::context() ) {
			return;
		}
		if ( 'archive' === self::context() && is_paged() ) {
			// One archive page lists every issue; paged URLs fold back into it.
			wp_safe_redirect( get_post_type_archive_link( Issues::TYPE ), 301 );
			exit;
		}
		if ( Settings::on( 'detach_theme' ) ) {
			self::detach_theme();
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), PHP_INT_MAX );
		add_action( 'wp_head', array( __CLASS__, 'head_html' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'rybbit' ), 50 );
	}

	private static function callback_file( $cb ): string {
		try {
			if ( is_string( $cb ) && str_contains( $cb, '::' ) ) {
				$cb = explode( '::', $cb, 2 );
			}
			if ( is_array( $cb ) && 2 === count( $cb ) ) {
				return (string) ( new \ReflectionMethod( $cb[0], $cb[1] ) )->getFileName();
			}
			if ( is_string( $cb ) || $cb instanceof \Closure ) {
				return (string) ( new \ReflectionFunction( $cb ) )->getFileName();
			}
			if ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
				return (string) ( new \ReflectionMethod( $cb, '__invoke' ) )->getFileName();
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}

	private static function detach_theme(): void {
		global $wp_filter;
		$root = wp_normalize_path( (string) realpath( get_theme_root() ) );
		if ( '' === $root ) {
			return;
		}
		foreach ( self::THEME_HOOKS as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$file = wp_normalize_path( self::callback_file( $cb['function'] ) );
					if ( $file && str_starts_with( $file, $root . '/' ) ) {
						remove_filter( $hook, $cb['function'], $priority );
					}
				}
			}
		}
	}

	public static function assets(): void {
		if ( Settings::on( 'detach_theme' ) ) {
			$theme_uris = array( get_theme_root_uri(), content_url( '/themes' ) );
			foreach ( array( wp_styles(), wp_scripts() ) as $deps ) {
				foreach ( $deps->queue as $handle ) {
					$src = (string) ( $deps->registered[ $handle ]->src ?? '' );
					foreach ( $theme_uris as $uri ) {
						if ( $src && str_starts_with( $src, $uri ) ) {
							$deps instanceof \WP_Styles ? wp_dequeue_style( $handle ) : wp_dequeue_script( $handle );
						}
					}
				}
			}
			// theme.json output belongs to the theme too.
			foreach ( array( 'global-styles', 'classic-theme-styles', 'core-block-supports' ) as $handle ) {
				wp_dequeue_style( $handle );
			}
		}
		$file = MAILERHIVE_DIR . 'assets/mailerhive.css';
		wp_enqueue_style( 'mailerhive', MAILERHIVE_URL . 'assets/mailerhive.css', array(), (string) filemtime( $file ) );
		wp_add_inline_style( 'mailerhive', self::inline_css() );
	}

	private static function inline_css(): string {
		$light = sanitize_hex_color( (string) Settings::get( 'accent_light' ) ) ?: '#C0392B';
		$dark  = sanitize_hex_color( (string) Settings::get( 'accent_dark' ) ) ?: '#E8836F';
		$l     = "--mh-accent:{$light};--mh-accent-hover:color-mix(in srgb,{$light} 82%,#000)";
		$d     = "--mh-accent:{$dark};--mh-accent-hover:color-mix(in srgb,{$dark} 78%,#fff)";
		$css   = ":root{{$l}}@media (prefers-color-scheme:dark){:root:not([data-theme=\"light\"]){{$d}}}:root[data-theme=\"dark\"]{{$d}}";
		$extra = (string) Settings::get( 'custom_css' );
		return $css . ( $extra ? "\n" . str_replace( '<', '', $extra ) : '' );
	}

	public static function head_html(): void {
		$html = (string) Settings::get( 'head_html' );
		if ( '' !== trim( $html ) ) {
			echo $html . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored, sanitized on save.
		}
	}

	public static function rybbit(): void {
		$src  = (string) Settings::get( 'rybbit_src' );
		$site = (string) Settings::get( 'rybbit_site_id' );
		if ( ! Settings::on( 'rybbit_enabled' ) || '' === $src || '' === $site ) {
			return;
		}
		printf( '<script src="%s" data-site-id="%s" defer></script>' . "\n", esc_url( $src ), esc_attr( $site ) );
	}

	public static function template( string $template ): string {
		switch ( self::context() ) {
			case 'issue':
				return MAILERHIVE_DIR . 'templates/issue.php';
			case 'archive':
				return MAILERHIVE_DIR . 'templates/archive.php';
			case 'mailerpress':
				return MAILERHIVE_DIR . 'templates/page.php';
		}
		return $template;
	}

	/* ---------- shared template parts ---------- */

	public static function landing_url(): string {
		$id = (int) Settings::get( 'landing_page_id' );
		return $id ? (string) get_permalink( $id ) : home_url( '/newsletter/' );
	}

	public static function avatar( int $size = 34 ): string {
		$url = (string) Settings::get( 'avatar_url' );
		if ( $url ) {
			return sprintf( '<img class="mh-avatar mh-avatar--img" src="%s" width="%2$d" height="%2$d" alt="">', esc_url( $url ), $size );
		}
		return '<span class="mh-avatar" aria-hidden="true">' . esc_html( (string) Settings::get( 'author_initials' ) ) . '</span>';
	}

	private static function link_href( string $url ): string {
		return str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ? home_url( $url ) : $url;
	}

	public static function head(): void {
		$scheme = (string) Settings::get( 'color_scheme' );
		$attr   = in_array( $scheme, array( 'light', 'dark' ), true ) ? ' data-theme="' . esc_attr( $scheme ) . '"' : '';
		?><!doctype html>
<html <?php language_attributes(); ?><?php echo $attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="<?php echo esc_attr( 'auto' === $scheme ? 'light dark' : $scheme ); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'mailerhive mh-' . self::context() ); ?>>
<?php wp_body_open(); ?>
<a class="mh-skip" href="#mh-main">Skip to content</a>
<header class="mh-top">
	<div class="mh-top__in">
		<a class="mh-brand" href="<?php echo esc_url( self::landing_url() ); ?>">
			<?php echo self::avatar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in avatar(). ?>
			<span class="mh-brand__txt"><b><?php echo esc_html( Settings::get( 'brand_name' ) ); ?></b><small>by <?php echo esc_html( Settings::get( 'author_name' ) ); ?></small></span>
		</a>
		<nav class="mh-nav" aria-label="Newsletter">
			<?php foreach ( Settings::link_lines( 'header_links' ) as $link ) : ?>
				<a href="<?php echo esc_url( self::link_href( $link[1] ) ); ?>"><?php echo esc_html( $link[0] ); ?></a>
			<?php endforeach; ?>
			<a class="mh-btn mh-btn--sm" href="<?php echo ( in_array( self::context(), array( 'issue', 'archive' ), true ) && Settings::on( 'show_subscribe' ) ) ? '#mh-subscribe' : esc_url( self::landing_url() ); ?>">Subscribe</a>
		</nav>
	</div>
</header>
		<?php
	}

	public static function subscribe_box(): void {
		if ( ! Settings::on( 'show_subscribe' ) ) {
			return;
		}
		$form  = Settings::form_shortcode();
		$facts = Settings::lines( 'subscribe_facts' );
		?>
<section class="mh-sub" id="mh-subscribe" aria-labelledby="mh-sub-h">
	<h2 id="mh-sub-h"><?php echo esc_html( Settings::get( 'subscribe_heading' ) ); ?></h2>
	<p><?php echo esc_html( Settings::get( 'tagline' ) ); ?></p>
	<?php if ( $form ) : ?>
		<div class="mh-sub__form"><?php echo do_shortcode( $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?></div>
	<?php else : ?>
		<p class="mh-sub__cta"><a class="mh-btn" href="<?php echo esc_url( self::landing_url() ); ?>">Subscribe</a></p>
	<?php endif; ?>
	<?php if ( $facts ) : ?>
		<ul class="mh-facts" role="list"><?php foreach ( $facts as $fact ) : ?><li><?php echo esc_html( $fact ); ?></li><?php endforeach; ?></ul>
	<?php endif; ?>
</section>
		<?php
	}

	public static function foot(): void {
		$note = (string) Settings::get( 'footer_note' );
		?>
<footer class="mh-foot">
	<div class="mh-foot__in">
		<?php if ( $note ) : ?><p class="mh-foot__note"><?php echo esc_html( $note ); ?></p><?php endif; ?>
		<nav class="mh-foot__links" aria-label="Footer">
			<a href="<?php echo esc_url( self::landing_url() ); ?>"><?php echo esc_html( Settings::get( 'brand_name' ) ); ?></a>
			<?php foreach ( Settings::link_lines( 'footer_links' ) as $link ) : ?>
				<a href="<?php echo esc_url( self::link_href( $link[1] ) ); ?>"><?php echo esc_html( $link[0] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<p class="mh-foot__copy">&copy; 2008&ndash;<?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( Settings::get( 'author_name' ) ); ?></p>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
		<?php
	}
}
