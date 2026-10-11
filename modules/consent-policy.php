<?php
/**
 * Module loader: Core Forms → MailerPress consent policy (Settings → Modules).
 */

defined( 'ABSPATH' ) || exit;

if ( MailerHive\Settings::on( 'module_consent_policy' ) ) {
	require_once __DIR__ . '/src/class-consent-policy.php';
}
