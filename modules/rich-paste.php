<?php
/**
 * Converts safe Google Docs and WordPress block content into native MailerPress
 * email blocks while preserving rich text and links.
 * Loads before MailerPress's editor bundle so the bridge handles rich HTML before
 * MailerPress's plain-text-only document paste handler runs. Settings → Modules.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'mailpress_enqueue_scripts',
	static function (): void {
		if ( ! MailerHive\Settings::on( 'module_rich_paste' ) ) {
			return;
		}
		if ( ! current_user_can( 'mailerpress_manage_campaigns' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$path = MAILERHIVE_DIR . 'assets/rich-paste/rich-paste.js';
		if ( ! is_readable( $path ) ) {
			return;
		}
		wp_enqueue_script(
			'mailerhive-rich-paste',
			MAILERHIVE_URL . 'assets/rich-paste/rich-paste.js',
			array(),
			(string) filemtime( $path ),
			true
		);
	}
);
