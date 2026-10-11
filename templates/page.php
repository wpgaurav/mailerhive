<?php
/**
 * MailerPress subscription pages (unsubscribe confirmation, manage preferences)
 * inside the MailerHive shell. MailerPress's own shortcode renders the form and
 * handles every token and status change; this template only supplies the frame.
 */

defined( 'ABSPATH' ) || exit;

use MailerHive\Shell;

Shell::head();
the_post();
?>
<main id="mh-main" class="mh-main">
	<article class="mh-mail mh-mail--page">
		<header class="mh-mail__head">
			<h1 class="mh-mail__subject"><?php the_title(); ?></h1>
		</header>
		<div class="mh-mail__body mh-prose mh-mp">
			<?php the_content(); ?>
		</div>
	</article>
</main>
<?php
Shell::foot();
