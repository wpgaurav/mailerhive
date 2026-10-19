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
		} elseif ( Settings::on( 'landing_shell' ) && (int) Settings::get( 'landing_page_id' ) && is_page( (int) Settings::get( 'landing_page_id' ) ) ) {
			$ctx = 'landing';
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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), PHP_INT_MAX );
		add_action( 'wp_footer', array( __CLASS__, 'dequeue_theme_assets' ), 19 );
		add_action( 'wp_head', array( __CLASS__, 'head_html' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'rybbit' ), 50 );
		add_action( 'wp_footer', array( __CLASS__, 'theme_toggle_script' ), 5 );
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

	/**
	 * Directories whose code counts as "the theme": the themes folder and, for
	 * Marketers Delight, its installed drop-ins (scripts, opt-ins, testimonials).
	 */
	private static function theme_roots(): array {
		$roots = array( get_theme_root() );
		if ( defined( 'MD_INSTALLED_DROPINS' ) ) {
			$roots[] = MD_INSTALLED_DROPINS;
		}
		$roots = (array) apply_filters( 'mailerhive_theme_roots', $roots );
		$out   = array();
		foreach ( $roots as $r ) {
			$real = realpath( (string) $r );
			if ( $real ) {
				$out[] = untrailingslashit( wp_normalize_path( $real ) );
			}
		}
		return array_unique( $out );
	}

	private static function in_roots( string $file, array $roots ): bool {
		foreach ( $roots as $root ) {
			if ( str_starts_with( $file, $root . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function late_detach(): void {
		self::detach_theme();
		remove_action( 'wp_head', 'wp_custom_css_cb', 101 ); // Customizer CSS belongs to the theme.
	}

	private static function detach_theme(): void {
		global $wp_filter;
		$roots = self::theme_roots();
		if ( ! $roots ) {
			return;
		}
		foreach ( self::THEME_HOOKS as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$file = wp_normalize_path( self::callback_file( $cb['function'] ) );
					if ( $file && self::in_roots( $file, $roots ) ) {
						remove_filter( $hook, $cb['function'], $priority );
					}
				}
			}
		}
	}

	public static function dequeue_theme_assets(): void {
		if ( ! Settings::on( 'detach_theme' ) ) {
			return;
		}
		foreach ( array( wp_styles(), wp_scripts() ) as $deps ) {
			foreach ( $deps->queue as $handle ) {
				$src = (string) ( $deps->registered[ $handle ]->src ?? '' );
				if ( $src && str_contains( $src, '/wp-content/themes/' ) ) {
					$deps instanceof \WP_Styles ? wp_dequeue_style( $handle ) : wp_dequeue_script( $handle );
				}
			}
		}
		// theme.json output belongs to the theme too.
		foreach ( array( 'global-styles', 'classic-theme-styles', 'core-block-supports' ) as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	public static function assets(): void {
		self::dequeue_theme_assets();
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
		wp_print_script_tag(
			array(
				'src'          => esc_url( $src ),
				'data-site-id' => $site,
				'defer'        => true,
			)
		);
	}

	public static function template( string $template ): string {
		// Detach at the very start of wp_head: the theme keeps registering head, asset
		// and footer callbacks through template_redirect and its own late
		// template_include runtime, all of which have run by then.
		if ( self::context() && Settings::on( 'detach_theme' ) ) {
			add_action( 'wp_head', array( __CLASS__, 'late_detach' ), PHP_INT_MIN );
		}
		switch ( self::context() ) {
			case 'issue':
				return MAILERHIVE_DIR . 'templates/issue.php';
			case 'archive':
				return MAILERHIVE_DIR . 'templates/archive.php';
			case 'landing':
				return MAILERHIVE_DIR . 'templates/landing.php';
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
		$id  = (int) Settings::get( 'avatar_id' );
		$url = $id ? (string) wp_get_attachment_image_url( $id, array( $size * 2, $size * 2 ) ) : (string) Settings::get( 'avatar_url' );
		if ( $url ) {
			return sprintf( '<img class="mh-avatar mh-avatar--img" src="%s" width="%2$d" height="%2$d" alt="">', esc_url( $url ), $size );
		}
		return '<span class="mh-avatar" aria-hidden="true">' . esc_html( Settings::initials() ) . '</span>';
	}

	public static function logo(): string {
		$light = (int) Settings::get( 'logo_id' );
		if ( ! $light ) {
			return '';
		}
		$dark  = (int) Settings::get( 'logo_dark_id' ) ?: $light;
		$width = (int) Settings::get( 'logo_width' ) ?: 140;
		$alt   = Settings::brand();
		$img   = static function ( int $id, string $class ) use ( $width, $alt ): string {
			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( ! $src ) {
				return '';
			}
			$h = $src[1] ? (int) round( $width * $src[2] / $src[1] ) : 0;
			return sprintf( '<img class="mh-logo %s" src="%s" width="%d"%s alt="%s">', esc_attr( $class ), esc_url( $src[0] ), $width, $h ? ' height="' . $h . '"' : '', esc_attr( $alt ) );
		};
		if ( $dark === $light ) {
			return $img( $light, '' );
		}
		return $img( $light, 'mh-logo--light' ) . $img( $dark, 'mh-logo--dark' );
	}

	public static function sender_line(): string {
		$line = trim( (string) Settings::get( 'sender_line' ) );
		if ( '' !== $line ) {
			return esc_html( $line );
		}
		return '<b>' . esc_html( Settings::author() ) . '</b> &middot; ' . esc_html( Settings::brand() );
	}

	public static function toggle_enabled(): bool {
		return Settings::on( 'show_theme_toggle' ) && 'auto' === Settings::get( 'color_scheme' );
	}

	/** Runs before first paint: apply a saved choice, otherwise leave the system setting in charge. */
	private static function theme_bootstrap(): void {
		if ( ! self::toggle_enabled() ) {
			return;
		}
		$key = esc_js( (string) ( Settings::get( 'theme_storage_key' ) ?: 'mailerhive-theme' ) );
		wp_print_inline_script_tag( "!function(){try{var t=localStorage.getItem('{$key}');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t)}}catch(e){}}();", array( 'id' => 'mailerhive-theme-bootstrap' ) );
	}

	public static function theme_toggle_script(): void {
		if ( ! self::toggle_enabled() ) {
			return;
		}
		$key   = esc_js( (string) ( Settings::get( 'theme_storage_key' ) ?: 'mailerhive-theme' ) );
		$light = esc_js( __( 'Switch to light mode', 'mailerhive' ) );
		$dark  = esc_js( __( 'Switch to dark mode', 'mailerhive' ) );
		wp_print_inline_script_tag( "!function(){var d=document.documentElement,b=document.querySelector('.mh-theme'),m=window.matchMedia('(prefers-color-scheme: dark)');if(!b)return;function cur(){return d.getAttribute('data-theme')||(m.matches?'dark':'light')}function sync(){var c=cur();b.setAttribute('aria-pressed',c==='dark'?'true':'false');b.setAttribute('aria-label',c==='dark'?'{$light}':'{$dark}');b.setAttribute('title',b.getAttribute('aria-label'))}b.addEventListener('click',function(){var n=cur()==='dark'?'light':'dark';d.setAttribute('data-theme',n);try{localStorage.setItem('{$key}',n)}catch(e){}sync()});m.addEventListener&&m.addEventListener('change',function(){sync()});sync()}();", array( 'id' => 'mailerhive-theme-toggle' ) );
	}

	/** Pick an icon for a footer link from its URL. */
	private static function link_icon( string $url ): string {
		$path = trim( (string) wp_parse_url( self::link_href( $url ), PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return 'home';
		}
		if ( str_contains( $path, Settings::issue_base() ) ) {
			return 'archive';
		}
		if ( str_contains( $path, 'privacy' ) ) {
			return 'shield-lock';
		}
		return 'arrow-up-right';
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
<?php self::theme_bootstrap(); ?>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'mailerhive mh-' . self::context() ); ?>>
<?php wp_body_open(); ?>
<a class="mh-skip" href="#mh-main"><?php esc_html_e( 'Skip to content', 'mailerhive' ); ?></a>
<header class="mh-top">
	<div class="mh-top__in">
		<?php
		$style  = (string) Settings::get( 'header_style' );
		$logo   = 'avatar' === $style ? '' : self::logo();
		$byline = (string) Settings::get( 'byline' );
		$sub    = ( 'landing' === self::context() || ( in_array( self::context(), array( 'issue', 'archive' ), true ) && Settings::on( 'show_subscribe' ) ) ) ? '#mh-subscribe' : self::landing_url();
		?>
		<a class="mh-brand<?php echo $logo && 'logo' === $style ? ' mh-brand--logo' : ''; ?>" href="<?php echo esc_url( self::landing_url() ); ?>"<?php echo $logo && 'logo' === $style ? ' aria-label="' . esc_attr( Settings::brand() ) . '"' : ''; ?>>
			<?php
			if ( $logo ) {
				echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in logo().
			} else {
				echo self::avatar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in avatar().
			}
			?>
			<?php if ( ! $logo || 'logo_text' === $style ) : ?>
				<span class="mh-brand__txt"><b><?php echo esc_html( Settings::brand() ); ?></b><?php if ( $byline ) : ?><small><?php echo esc_html( $byline ); ?></small><?php endif; ?></span>
			<?php endif; ?>
		</a>
		<nav class="mh-nav" aria-label="<?php esc_attr_e( 'Newsletter', 'mailerhive' ); ?>">
			<?php foreach ( Settings::link_lines( 'header_links' ) as $i => $link ) : ?>
				<a class="mh-nav__link" href="<?php echo esc_url( self::link_href( $link[1] ) ); ?>"><?php echo 0 === $i ? Icons::get( 'list-details' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $link[0] ); ?></span></a>
			<?php endforeach; ?>
			<span class="mh-nav__end">
				<?php if ( self::toggle_enabled() ) : ?>
					<button type="button" class="mh-theme" aria-pressed="false" aria-label="<?php esc_attr_e( 'Switch color mode', 'mailerhive' ); ?>"><?php echo Icons::get( 'sun', 'mh-theme__sun' ) . Icons::get( 'moon', 'mh-theme__moon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<?php endif; ?>
				<a class="mh-btn mh-btn--sm" href="<?php echo esc_url( $sub ); ?>"><?php echo Icons::get( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( Settings::get( 'subscribe_label' ) ?: __( 'Subscribe', 'mailerhive' ) ); ?></span></a>
			</span>
		</nav>
	</div>
</header>
		<?php
	}

	/**
	 * Share links for one issue: plain intent URLs (no third-party scripts) plus copy-link.
	 */
	public static function share_bar( \WP_Post $post ): void {
		if ( ! Settings::on( 'show_share' ) ) {
			return;
		}
		$url   = (string) get_permalink( $post );
		$title = wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
		$both  = $title . ' ' . $url;
		$nets  = array(
			'x'        => array( __( 'X', 'mailerhive' ), 'brand-x', 'https://x.com/intent/post?' . http_build_query( array( 'text' => $title, 'url' => $url ), '', '&', PHP_QUERY_RFC3986 ) ),
			'linkedin' => array( __( 'LinkedIn', 'mailerhive' ), 'brand-linkedin', 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $url ) ),
			'facebook' => array( __( 'Facebook', 'mailerhive' ), 'brand-facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ) ),
			'bluesky'  => array( __( 'Bluesky', 'mailerhive' ), 'brand-bluesky', 'https://bsky.app/intent/compose?text=' . rawurlencode( $both ) ),
			'threads'  => array( __( 'Threads', 'mailerhive' ), 'brand-threads', 'https://www.threads.net/intent/post?text=' . rawurlencode( $both ) ),
			'whatsapp' => array( __( 'WhatsApp', 'mailerhive' ), 'brand-whatsapp', 'https://wa.me/?text=' . rawurlencode( $both ) ),
			'email'    => array( __( 'email', 'mailerhive' ), 'mail', 'mailto:?' . http_build_query( array( 'subject' => $title, 'body' => $url ), '', '&', PHP_QUERY_RFC3986 ) ),
		);
		$nets  = (array) apply_filters( 'mailerhive_share_networks', $nets, $post );
		if ( ! $nets ) {
			return;
		}
		?>
		<footer class="mh-share">
			<p class="mh-share__label" id="mh-share-label"><?php echo Icons::get( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Share this issue', 'mailerhive' ); ?></p>
			<ul class="mh-share__list" aria-labelledby="mh-share-label">
				<?php
				foreach ( $nets as $key => $net ) :
					list( $name, $icon, $href ) = array_pad( array_values( (array) $net ), 3, '' );
					/* translators: %s: network name, e.g. LinkedIn */
					$label = sprintf( __( 'Share on %s', 'mailerhive' ), $name );
					if ( 'email' === $key ) {
						$label = __( 'Share by email', 'mailerhive' );
					}
					$mail = 0 === strpos( (string) $href, 'mailto:' );
					?>
					<li><a class="mh-share__btn mh-share__btn--<?php echo esc_attr( sanitize_key( (string) $key ) ); ?>" href="<?php echo esc_url( (string) $href, array( 'http', 'https', 'mailto' ) ); ?>"<?php echo $mail ? '' : ' target="_blank" rel="noopener noreferrer nofollow"'; ?> aria-label="<?php echo esc_attr( $label ); ?>" title="<?php echo esc_attr( $label ); ?>"><?php echo Icons::get( (string) $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></li>
				<?php endforeach; ?>
				<li><button type="button" class="mh-share__btn mh-share__copy" data-url="<?php echo esc_url( $url ); ?>" data-done="<?php esc_attr_e( 'Link copied', 'mailerhive' ); ?>" aria-label="<?php esc_attr_e( 'Copy link', 'mailerhive' ); ?>" title="<?php esc_attr_e( 'Copy link', 'mailerhive' ); ?>"><?php echo Icons::get( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button></li>
			</ul>
			<span class="mh-sr" role="status" aria-live="polite"></span>
		</footer>
		<?php
		wp_print_inline_script_tag( "!function(){var b=document.querySelector('.mh-share__copy');if(!b||!navigator.clipboard){b&&b.parentNode.remove();return}var s=document.querySelector('.mh-share [role=status]'),l=b.getAttribute('aria-label'),t;b.addEventListener('click',function(){navigator.clipboard.writeText(b.dataset.url).then(function(){b.classList.add('is-copied');b.setAttribute('aria-label',b.dataset.done);b.title=b.dataset.done;s.textContent=b.dataset.done;clearTimeout(t);t=setTimeout(function(){b.classList.remove('is-copied');b.setAttribute('aria-label',l);b.title=l;s.textContent=''},2000)})})}();", array( 'id' => 'mailerhive-share' ) );
	}

	public static function subscribe_box( string $id = 'mh-subscribe' ): void {
		if ( ! Settings::on( 'show_subscribe' ) ) {
			return;
		}
		$form  = Settings::form_shortcode();
		$facts = Settings::lines( 'subscribe_facts' );
		?>
<section class="mh-sub" id="<?php echo esc_attr( $id ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>-h">
	<span class="mh-sub__icon"><?php echo Icons::get( 'mail-opened' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<h2 id="<?php echo esc_attr( $id ); ?>-h"><?php echo esc_html( Settings::subscribe_heading() ); ?></h2>
	<?php if ( Settings::tagline() ) : ?><p><?php echo esc_html( Settings::tagline() ); ?></p><?php endif; ?>
	<?php if ( $form ) : ?>
		<div class="mh-sub__form"><?php echo do_shortcode( $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?></div>
	<?php else : ?>
		<p class="mh-sub__cta"><a class="mh-btn" href="<?php echo esc_url( self::landing_url() ); ?>"><?php echo Icons::get( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Subscribe', 'mailerhive' ); ?></span></a></p>
	<?php endif; ?>
	<?php if ( $facts ) : ?>
		<ul class="mh-facts" role="list"><?php foreach ( $facts as $fact ) : ?><li><?php echo Icons::get( 'circle-check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $fact ); ?></li><?php endforeach; ?></ul>
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
			<a href="<?php echo esc_url( self::landing_url() ); ?>"><?php echo Icons::get( 'news' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( Settings::brand() ); ?></span></a>
			<?php foreach ( Settings::link_lines( 'footer_links' ) as $link ) : ?>
				<a href="<?php echo esc_url( self::link_href( $link[1] ) ); ?>"><?php echo Icons::get( self::link_icon( $link[1] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $link[0] ); ?></span></a>
			<?php endforeach; ?>
		</nav>
		<?php if ( Settings::copyright() ) : ?><p class="mh-foot__copy"><?php echo esc_html( Settings::copyright() ); ?></p><?php endif; ?>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
		<?php
	}
}
