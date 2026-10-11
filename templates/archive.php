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
$mh_issues = get_posts(
	array(
		'post_type'      => Issues::TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	)
);
$mh_years = array();
foreach ( $mh_issues as $mh_issue ) {
	$mh_years[ get_the_date( 'Y', $mh_issue ) ][] = $mh_issue;
}
/* translators: %s: newsletter name */
$mh_title = (string) Settings::get( 'archive_title' ) ?: sprintf( __( '%s: every issue', 'mailerhive' ), Settings::brand() );
$mh_intro = (string) Settings::get( 'archive_intro' ) ?: Settings::tagline();
?>
<main id="mh-main" class="mh-main">
	<header class="mh-archive__head">
		<span class="mh-archive__icon"><?php echo Icons::get( 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<h1><?php echo esc_html( $mh_title ); ?></h1>
		<?php if ( $mh_intro ) : ?><p><?php echo esc_html( $mh_intro ); ?></p><?php endif; ?>
	</header>

	<?php if ( ! $mh_issues ) : ?>
		<p class="mh-archive__empty"><?php esc_html_e( 'The first issue is on its way.', 'mailerhive' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $mh_years as $mh_year => $mh_list ) : ?>
	<section class="mh-year" aria-labelledby="mh-y-<?php echo esc_attr( $mh_year ); ?>">
		<h2 id="mh-y-<?php echo esc_attr( $mh_year ); ?>"><?php echo Icons::get( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $mh_year ); ?></h2>
		<ol class="mh-list" role="list">
			<?php foreach ( $mh_list as $mh_issue ) : ?>
			<li>
				<a class="mh-row" href="<?php echo esc_url( get_permalink( $mh_issue ) ); ?>">
					<time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d', $mh_issue ) ); ?>"><?php echo esc_html( get_the_date( 'M j', $mh_issue ) ); ?></time>
					<span class="mh-row__main"><span class="mh-row__t"><?php echo esc_html( get_the_title( $mh_issue ) ); ?></span><span class="mh-row__d"><?php echo esc_html( wp_trim_words( Issues::subtitle( $mh_issue ), 24 ) ); ?></span></span>
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
