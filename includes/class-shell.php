<?php
/**
 * The MailerHive reading shell. Issue pages, the issue archive and MailerPress
 * subscription pages render through MailerHive's own templates with a dedicated
 * header and footer. Everything the active theme hooks into the document (styles,
 * scripts, head/footer output, content filters) is detached for these requests;
 * WordPress core output and every plugin keep working, including Functionalities
 * fonts, Rank Math, Core Forms and Perfmatters. Rybbit is printed here because the
 * site's tag normally comes from the theme's footer scripts.
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
		} elseif ( self::is_mailerpress_page() ) {
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
		self::detach_theme();
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), PHP_INT_MAX );
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
		$file = MAILERHIVE_DIR . 'assets/mailerhive.css';
		wp_enqueue_style( 'mailerhive', MAILERHIVE_URL . 'assets/mailerhive.css', array(), (string) filemtime( $file ) );
	}

	public static function rybbit(): void {
		$src  = (string) Settings::get( 'rybbit_src' );
		$site = (string) Settings::get( 'rybbit_site_id' );
		if ( '' === $src || '' === $site ) {
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

	public static function head(): void {
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'mailerhive mh-' . self::context() ); ?>>
<?php wp_body_open(); ?>
<a class="mh-skip" href="#mh-main">Skip to content</a>
<header class="mh-top">
	<div class="mh-top__in">
		<a class="mh-brand" href="<?php echo esc_url( self::landing_url() ); ?>">
			<span class="mh-avatar" aria-hidden="true"><?php echo esc_html( Settings::get( 'author_initials' ) ); ?></span>
			<span class="mh-brand__txt"><b><?php echo esc_html( Settings::get( 'brand_name' ) ); ?></b><small>by <?php echo esc_html( Settings::get( 'author_name' ) ); ?></small></span>
		</a>
		<nav class="mh-nav" aria-label="Newsletter">
			<a href="<?php echo esc_url( get_post_type_archive_link( Issues::TYPE ) ); ?>">All issues</a>
			<a class="mh-btn mh-btn--sm" href="<?php echo 'issue' === self::context() || 'archive' === self::context() ? '#mh-subscribe' : esc_url( self::landing_url() ); ?>">Subscribe</a>
		</nav>
	</div>
</header>
		<?php
	}

	public static function subscribe_box(): void {
		$form = (string) Settings::get( 'form_shortcode' );
		?>
<section class="mh-sub" id="mh-subscribe" aria-labelledby="mh-sub-h">
	<h2 id="mh-sub-h">Get the next note from my workbench</h2>
	<p><?php echo esc_html( Settings::get( 'tagline' ) ); ?></p>
	<div class="mh-sub__form"><?php echo do_shortcode( $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?></div>
	<ul class="mh-facts" role="list"><li>Free</li><li>No fixed schedule</li><li>Unsubscribe from any issue</li></ul>
</section>
		<?php
	}

	public static function foot(): void {
		$privacy = (string) Settings::get( 'privacy_url' );
		?>
<footer class="mh-foot">
	<div class="mh-foot__in">
		<p class="mh-foot__note">Already subscribed? Every email ends with links to manage or cancel your subscription.</p>
		<nav class="mh-foot__links" aria-label="Footer">
			<a href="<?php echo esc_url( self::landing_url() ); ?>"><?php echo esc_html( Settings::get( 'brand_name' ) ); ?></a>
			<a href="<?php echo esc_url( get_post_type_archive_link( Issues::TYPE ) ); ?>">All issues</a>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">gauravtiwari.org</a>
			<?php if ( $privacy ) : ?><a href="<?php echo esc_url( $privacy ); ?>">Privacy</a><?php endif; ?>
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
