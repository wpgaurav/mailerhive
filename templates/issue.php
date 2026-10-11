<?php
/**
 * Single newsletter issue, rendered as the email it was.
 */

defined( 'ABSPATH' ) || exit;

use MailerHive\Icons;
use MailerHive\Issues;
use MailerHive\Settings;
use MailerHive\Shell;

Shell::head();
the_post();
$mailerhive_post       = get_post();
$mailerhive_subtitle   = Issues::subtitle( $mailerhive_post );
$mailerhive_neighbours = Issues::neighbours( $mailerhive_post );
$mailerhive_words      = str_word_count( wp_strip_all_tags( (string) $mailerhive_post->post_content ) );
$mailerhive_minutes    = max( 1, (int) round( $mailerhive_words / 230 ) );
?>
<main id="mh-main" class="mh-main">
	<article class="mh-mail" aria-labelledby="mh-subject">
		<header class="mh-mail__from">
			<?php echo Shell::avatar( 76 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<p class="mh-mail__sender"><?php echo Shell::sender_line(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in sender_line(). ?></p>
			<p class="mh-mail__meta">
				<span><?php echo Icons::get( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></span>
				<span><?php echo Icons::get( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php /* translators: %d: minutes */ echo esc_html( sprintf( _n( '%d min read', '%d min read', $mailerhive_minutes, 'mailerhive' ), $mailerhive_minutes ) ); ?></span>
			</p>
		</header>
		<div class="mh-mail__head">
			<h1 class="mh-mail__subject" id="mh-subject"><?php the_title(); ?></h1>
			<?php if ( $mailerhive_subtitle ) : ?>
				<p class="mh-mail__dek"><?php echo esc_html( $mailerhive_subtitle ); ?></p>
			<?php endif; ?>
		</div>
		<div class="mh-mail__body mh-prose">
			<?php
			$mailerhive_body = apply_filters( 'mailerhive_issue_body', null, $mailerhive_post );
			if ( null === $mailerhive_body ) {
				the_content();
			} else {
				echo $mailerhive_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered content from the paid module.
			}
			?>
		</div>
	</article>

	<?php if ( Settings::on( 'show_pager' ) && ( $mailerhive_neighbours['older'] || $mailerhive_neighbours['newer'] ) ) : ?>
	<nav class="mh-pager" aria-label="<?php esc_attr_e( 'More issues', 'mailerhive' ); ?>">
		<?php if ( $mailerhive_neighbours['older'] ) : ?>
			<a class="mh-pager__link" href="<?php echo esc_url( get_permalink( $mailerhive_neighbours['older'] ) ); ?>" rel="prev"><small><?php echo Icons::get( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Previous issue', 'mailerhive' ); ?></small><span><?php echo esc_html( get_the_title( $mailerhive_neighbours['older'] ) ); ?></span></a>
		<?php else : ?><span></span><?php endif; ?>
		<?php if ( $mailerhive_neighbours['newer'] ) : ?>
			<a class="mh-pager__link mh-pager__link--next" href="<?php echo esc_url( get_permalink( $mailerhive_neighbours['newer'] ) ); ?>" rel="next"><small><?php esc_html_e( 'Next issue', 'mailerhive' ); ?><?php echo Icons::get( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small><span><?php echo esc_html( get_the_title( $mailerhive_neighbours['newer'] ) ); ?></span></a>
		<?php endif; ?>
	</nav>
	<?php endif; ?>

	<?php Shell::subscribe_box(); ?>
</main>
<?php
Shell::foot();
