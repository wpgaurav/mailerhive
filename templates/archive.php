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
$issues = get_posts(
	array(
		'post_type'      => Issues::TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	)
);
$years = array();
foreach ( $issues as $issue ) {
	$years[ get_the_date( 'Y', $issue ) ][] = $issue;
}
/* translators: %s: newsletter name */
$title = (string) Settings::get( 'archive_title' ) ?: sprintf( __( '%s: every issue', 'mailerhive' ), Settings::brand() );
$intro = (string) Settings::get( 'archive_intro' ) ?: Settings::tagline();
?>
<main id="mh-main" class="mh-main">
	<header class="mh-archive__head">
		<span class="mh-archive__icon"><?php echo Icons::get( 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<h1><?php echo esc_html( $title ); ?></h1>
		<?php if ( $intro ) : ?><p><?php echo esc_html( $intro ); ?></p><?php endif; ?>
	</header>

	<?php if ( ! $issues ) : ?>
		<p class="mh-archive__empty"><?php esc_html_e( 'The first issue is on its way.', 'mailerhive' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $years as $year => $list ) : ?>
	<section class="mh-year" aria-labelledby="mh-y-<?php echo esc_attr( $year ); ?>">
		<h2 id="mh-y-<?php echo esc_attr( $year ); ?>"><?php echo Icons::get( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $year ); ?></h2>
		<ol class="mh-list" role="list">
			<?php foreach ( $list as $issue ) : ?>
			<li>
				<a class="mh-row" href="<?php echo esc_url( get_permalink( $issue ) ); ?>">
					<time datetime="<?php echo esc_attr( get_the_date( 'Y-m-d', $issue ) ); ?>"><?php echo esc_html( get_the_date( 'M j', $issue ) ); ?></time>
					<span class="mh-row__main"><span class="mh-row__t"><?php echo esc_html( get_the_title( $issue ) ); ?></span><span class="mh-row__d"><?php echo esc_html( wp_trim_words( Issues::subtitle( $issue ), 24 ) ); ?></span></span>
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
