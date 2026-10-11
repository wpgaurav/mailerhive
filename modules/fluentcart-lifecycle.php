<?php
/**
 * FluentCart purchase, refund and abandoned-checkout classification for MailerPress.
 * Moved from mu-plugins/gt-mailerpress-fluentcart.php into MailerHive (2026-10-11). Logic unchanged.
 * Skips itself while the old MU copy is still loaded, so the move is seamless.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'GT_MailerPress_FluentCart_Lifecycle', false ) ) {
	return;
}

require_once __DIR__ . '/src/class-fluentcart-lifecycle.php';
