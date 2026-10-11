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
$mailerhive_issues = get_posts(
	array(
		'post_type'      => Issues::TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	)
);
$mailerhive_years = array();
foreach ( $mailerhive_issues as $mailerhive_issue ) {
	$mailerhive_years[ get_the_date( 'Y', $mailerhive_issue ) ][] = $mailerhive_issue;
}
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

	<?php if ( ! $mailerhive_issues ) : ?>
		<p class="mh-archive__empty"><?php esc_html_e( 'The first issue is on its way.', 'mailerhive' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $mailerhive_years as $mailerhive_year => $mailerhive_list ) : ?>
	<section class="mh-year" aria-labelledby="mh-y-<?php echo esc_attr( $mailerhive_year ); ?>">
		<h2 id="mh-y-<?php echo esc_attr( $mailerhive_year ); ?>"><?php echo Icons::get( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $mailerhive_year ); ?></h2>
		<ol class="mh-list" role="list">
			<?php foreach ( $mailerhive_list as $mailerhive_issue ) : ?>
			<li>
				<a class="mh-row" href="<?php echo esc_url( get_permalink( $mailerhive_issue ) ); ?>">
					<time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d', $mailerhive_issue ) ); ?>"><?php echo esc_html( get_the_date( 'M j', $mailerhive_issue ) ); ?></time>
					<span class="mh-row__main"><span class="mh-row__t"><?php echo esc_html( get_the_title( $mailerhive_issue ) ); ?><?php echo apply_filters( 'mailerhive_issue_badge', '', $mailerhive_issue ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the filter callback. ?></span><span class="mh-row__d"><?php echo esc_html( wp_trim_words( Issues::subtitle( $mailerhive_issue ), 24 ) ); ?></span></span>
					<span class="mh-row__go"><?php echo Icons::get( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</a>
			</li>
			<?php endforeach; ?>
		</ol>
	</section>
	<?php endforeach; ?>

	<?php Shell::subscribe_box(); ?>
</main>
<?php
Shell::foot();
