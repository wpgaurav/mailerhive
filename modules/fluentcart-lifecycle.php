<?php
/**
 * Module loader: FluentCart → MailerPress lifecycle classification (Settings → Modules).
 */

defined( 'ABSPATH' ) || exit;

if ( MailerHive\Settings::on( 'module_fluentcart_lifecycle' ) ) {
	require_once __DIR__ . '/src/class-fluentcart-lifecycle.php';
}
