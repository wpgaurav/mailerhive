<?php
/**
 * The newsletter page (Settings → General → Newsletter page) in the MailerHive design:
 * a signup card, recent issues, then the page's own content as an editable prose card.
 */

defined( 'ABSPATH' ) || exit;

use MailerHive\Icons;
use MailerHive\Issues;
use MailerHive\Settings;
use MailerHive\Shell;

Shell::head();
the_post();
$mailerhive_form   = Settings::form_shortcode();
$mailerhive_facts  = Settings::lines( 'subscribe_facts' );
$mailerhive_byline = (string) Settings::get( 'byline' );
$mailerhive_recent = get_posts(
	array(
		'post_type'      => Issues::TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => max( 0, (int) Settings::get( 'landing_recent' ) ),
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	)
);
$mailerhive_count = (int) wp_count_posts( Issues::TYPE )->publish;
?>
<main id="mh-main" class="mh-main mh-landing">
	<section class="mh-mail mh-hero" aria-labelledby="mh-hero-h">
		<header class="mh-mail__from">
			<?php echo Shell::avatar( 76 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $mailerhive_byline ) : ?><p class="mh-mail__sender"><?php echo esc_html( $mailerhive_byline ); ?></p><?php endif; ?>
			<p class="mh-mail__meta">
				<?php if ( $mailerhive_count ) : ?>
					<span><?php echo Icons::get( 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php /* translators: %d: number of issues */ echo esc_html( sprintf( _n( '%d issue', '%d issues', $mailerhive_count, 'mailerhive' ), $mailerhive_count ) ); ?></span>
				<?php endif; ?>
				<span><?php echo Icons::get( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Free by email', 'mailerhive' ); ?></span>
			</p>
		</header>
		<div class="mh-hero__body">
			<h1 class="mh-mail__subject" id="mh-hero-h"><?php echo esc_html( Settings::brand() ); ?></h1>
			<?php if ( Settings::tagline() ) : ?><p class="mh-mail__dek"><?php echo esc_html( Settings::tagline() ); ?></p><?php endif; ?>
			<?php if ( $mailerhive_form ) : ?>
				<div class="mh-sub__form" id="mh-subscribe"><?php echo do_shortcode( $mailerhive_form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?></div>
			<?php endif; ?>
			<?php if ( $mailerhive_facts ) : ?>
				<ul class="mh-facts" role="list"><?php foreach ( $mailerhive_facts as $mailerhive_fact ) : ?><li><?php echo Icons::get( 'circle-check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $mailerhive_fact ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<?php if ( $mailerhive_recent ) : ?>
				<p class="mh-hero__alt"><a href="#mh-recent"><?php esc_html_e( 'Read a past issue first', 'mailerhive' ); ?><?php echo Icons::get( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></p>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $mailerhive_recent ) : ?>
	<section class="mh-year mh-recent" id="mh-recent" aria-labelledby="mh-recent-h">
		<h2 id="mh-recent-h"><?php echo Icons::get( 'news' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Recent issues', 'mailerhive' ); ?></h2>
		<ol class="mh-list" role="list">
			<?php foreach ( $mailerhive_recent as $mailerhive_issue ) : ?>
			<li>
				<a class="mh-row" href="<?php echo esc_url( get_permalink( $mailerhive_issue ) ); ?>">
					<time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d', $mailerhive_issue ) ); ?>"><?php echo esc_html( get_the_date( 'M j', $mailerhive_issue ) ); ?></time>
					<span class="mh-row__main"><span class="mh-row__t"><?php echo esc_html( get_the_title( $mailerhive_issue ) ); ?></span><span class="mh-row__d"><?php echo esc_html( wp_trim_words( Issues::subtitle( $mailerhive_issue ), 24 ) ); ?></span></span>
					<span class="mh-row__go"><?php echo Icons::get( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</a>
			</li>
			<?php endforeach; ?>
		</ol>
		<p class="mh-recent__all"><a href="<?php echo esc_url( get_post_type_archive_link( Issues::TYPE ) ); ?>"><?php echo Icons::get( 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'See every issue', 'mailerhive' ); ?></a></p>
	</section>
	<?php endif; ?>

	<?php if ( '' !== trim( (string) get_the_content() ) ) : ?>
	<article class="mh-mail mh-mail--page mh-landing__content">
		<div class="mh-mail__body mh-prose">
			<?php the_content(); ?>
		</div>
	</article>
	<?php endif; ?>

	<?php Shell::subscribe_box( 'mh-subscribe-end' ); ?>
</main>
<?php
Shell::foot();
