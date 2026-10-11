<?php
/**
 * Site consent and suppression policy around the native Core Forms MailerPress action.
 * Moved from mu-plugins/gt-core-forms-mailerpress-policy.php into MailerHive (2026-10-11). Logic unchanged.
 * Skips itself while the old MU copy is still loaded, so the move is seamless.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'GT_Core_Forms_MailerPress_Policy', false ) ) {
	return;
}

require_once __DIR__ . '/src/class-consent-policy.php';
