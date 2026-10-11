<?php
/**
 * Single newsletter issue, rendered as the email it was.
 */

defined( 'ABSPATH' ) || exit;

use MailerHive\Issues;
use MailerHive\Settings;
use MailerHive\Shell;

Shell::head();
the_post();
$post       = get_post();
$subtitle   = Issues::subtitle( $post );
$neighbours = Issues::neighbours( $post );
?>
<main id="mh-main" class="mh-main">
	<article class="mh-mail" aria-labelledby="mh-subject">
		<div class="mh-mail__bar">
			<span class="mh-mail__from"><span class="mh-avatar" aria-hidden="true"><?php echo esc_html( Settings::get( 'author_initials' ) ); ?></span><span><b><?php echo esc_html( Settings::get( 'author_name' ) ); ?></b> &middot; <?php echo esc_html( Settings::get( 'brand_name' ) ); ?></span></span>
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'F j, Y' ) ); ?></time>
		</div>
		<header class="mh-mail__head">
			<h1 class="mh-mail__subject" id="mh-subject"><?php the_title(); ?></h1>
			<?php if ( $subtitle ) : ?>
				<p class="mh-mail__dek"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>
		</header>
		<div class="mh-mail__body mh-prose">
			<?php the_content(); ?>
		</div>
	</article>

	<?php if ( $neighbours['older'] || $neighbours['newer'] ) : ?>
	<nav class="mh-pager" aria-label="More issues">
		<?php if ( $neighbours['older'] ) : ?>
			<a class="mh-pager__link" href="<?php echo esc_url( get_permalink( $neighbours['older'] ) ); ?>" rel="prev"><small>Previous issue</small><span><?php echo esc_html( get_the_title( $neighbours['older'] ) ); ?></span></a>
		<?php else : ?><span></span><?php endif; ?>
		<?php if ( $neighbours['newer'] ) : ?>
			<a class="mh-pager__link mh-pager__link--next" href="<?php echo esc_url( get_permalink( $neighbours['newer'] ) ); ?>" rel="next"><small>Next issue</small><span><?php echo esc_html( get_the_title( $neighbours['newer'] ) ); ?></span></a>
		<?php endif; ?>
	</nav>
	<?php endif; ?>

	<?php Shell::subscribe_box(); ?>
</main>
<?php
Shell::foot();
