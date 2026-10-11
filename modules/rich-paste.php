<?php
/**
 * Converts safe Google Docs and WordPress block content into native MailerPress
 * email blocks while preserving rich text and links.
 * Moved from mu-plugins/gt-mailerpress-rich-paste.php into MailerHive (2026-10-11).
 * Loads before MailerPress's editor bundle so the bridge handles rich HTML before
 * MailerPress's plain-text-only document paste handler runs. While the old MU copy
 * is still present it enqueues the same handle first and this call is a no-op.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'mailpress_enqueue_scripts',
	static function (): void {
		if ( ! current_user_can( 'mailerpress_manage_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$path = MAILERHIVE_DIR . 'assets/rich-paste/rich-paste.js';
		if ( ! is_readable( $path ) ) {
			return;
		}
		wp_enqueue_script(
			'gt-mailerpress-rich-paste',
			MAILERHIVE_URL . 'assets/rich-paste/rich-paste.js',
			array(),
			(string) filemtime( $path ),
			true
		);
	}
);
