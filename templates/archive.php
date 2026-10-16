<?php
/**
 * Every published issue, newest first, grouped by year.
 */

defined( 'ABSPATH' ) || exit;

use MailerHive\Icons;
use MailerHive\Issues;
use MailerHive\Settings;
use MailerHive\Shell;

Shell::head();
/* translators: %s: newsletter name */
$mailerhive_title = (string) Settings::get( 'archive_title' ) ?: sprintf( __( '%s: every issue', 'mailerhive' ), Settings::brand() );
$mailerhive_intro = (string) Settings::get( 'archive_intro' ) ?: Settings::tagline();
?>
<main id="mh-main" class="mh-main">
	<header class="mh-archive__head">
		<span class="mh-archive__icon"><?php echo Icons::get( 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<h1><?php echo esc_html( $mailerhive_title ); ?></h1>
		<?php if ( $mailerhive_intro ) : ?><p><?php echo esc_html( $mailerhive_intro ); ?></p><?php endif; ?>
	</header>

	<?php if ( ! have_posts() ) : ?>
		<p class="mh-archive__empty"><?php esc_html_e( 'The first issue is on its way.', 'mailerhive' ); ?></p>
	<?php else : ?>
		<?php $mailerhive_year = ''; ?>
		<?php while ( have_posts() ) : ?>
			<?php
			the_post();
			$mailerhive_issue     = get_post();
			$mailerhive_this_year = get_the_date( 'Y', $mailerhive_issue );
			if ( $mailerhive_this_year !== $mailerhive_year ) :
				if ( '' !== $mailerhive_year ) :
					?>
		</ol>
	</section>
					<?php
				endif;
				$mailerhive_year = $mailerhive_this_year;
				?>
	<section class="mh-year" aria-labelledby="mh-y-<?php echo esc_attr( $mailerhive_year ); ?>">
		<h2 id="mh-y-<?php echo esc_attr( $mailerhive_year ); ?>"><?php echo Icons::get( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $mailerhive_year ); ?></h2>
		<ol class="mh-list" role="list">
				<?php
			endif;
			?>
			<li>
				<a class="mh-row" href="<?php echo esc_url( get_permalink( $mailerhive_issue ) ); ?>">
					<time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d', $mailerhive_issue ) ); ?>"><?php echo esc_html( get_the_date( 'M j', $mailerhive_issue ) ); ?></time>
					<span class="mh-row__main"><span class="mh-row__t"><?php echo esc_html( get_the_title( $mailerhive_issue ) ); ?><?php echo apply_filters( 'mailerhive_issue_badge', '', $mailerhive_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the filter callback. ?></span><span class="mh-row__d"><?php echo esc_html( wp_trim_words( Issues::subtitle( $mailerhive_issue ), 24 ) ); ?></span></span>
					<span class="mh-row__go"><?php echo Icons::get( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</a>
			</li>
		<?php endwhile; ?>
		<?php if ( '' !== $mailerhive_year ) : ?>
		</ol>
	</section>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	$mailerhive_pages = paginate_links(
		array(
			'type'      => 'list',
			'mid_size'  => 1,
			'prev_text' => __( 'Newer', 'mailerhive' ),
			'next_text' => __( 'Older', 'mailerhive' ),
		)
	);
	if ( $mailerhive_pages ) :
		?>
	<nav class="mh-pager" aria-label="<?php esc_attr_e( 'Issue pages', 'mailerhive' ); ?>"><?php echo wp_kses_post( $mailerhive_pages ); ?></nav>
		<?php
	endif;
	?>

	<?php Shell::subscribe_box(); ?>
</main>
<?php
Shell::foot();
