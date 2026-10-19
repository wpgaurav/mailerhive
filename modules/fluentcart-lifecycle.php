<?php
/**
 * Module loader: FluentCart → MailerPress lifecycle classification (Settings → Modules).
 */

defined( 'ABSPATH' ) || exit;

if ( MailerHive\Settings::on( 'module_fluentcart_lifecycle' ) ) {
	require_once __DIR__ . '/src/class-fluentcart-lifecycle.php';
} else {
	// Switched off: drop the daily sweep it scheduled, which nothing handles now.
	add_action(
		'init',
		static function (): void {
			if ( wp_next_scheduled( 'mailerhive_fc_abandoned_sweep' ) ) {
				wp_clear_scheduled_hook( 'mailerhive_fc_abandoned_sweep' );
			}
		}
	);
}
