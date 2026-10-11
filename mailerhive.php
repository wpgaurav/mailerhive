<?php
/**
 * Plugin Name:       MailerHive
 * Description:       Beehiiv- and Ghost-style newsletter publishing for MailerPress: a public web archive of issues, a theme-free reading page with light/dark mode, one-click MailerPress drafts, Substack import, and optional consent, FluentCart and editor modules.
 * Version:           1.3.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Gaurav Tiwari
 * Author URI:        https://gauravtiwari.org
 * License:           GPL-2.0-or-later
 * Text Domain:       mailerhive
 * Update URI:        https://gauravtiwari.org/product/mailerhive/
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MAILERHIVE_VERSION', '1.3.0' );
define( 'MAILERHIVE_FILE', __FILE__ );
define( 'MAILERHIVE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAILERHIVE_URL', plugin_dir_url( __FILE__ ) );

require_once MAILERHIVE_DIR . 'includes/class-settings.php';
require_once MAILERHIVE_DIR . 'includes/class-icons.php';
require_once MAILERHIVE_DIR . 'includes/class-issues.php';
require_once MAILERHIVE_DIR . 'includes/class-content.php';
require_once MAILERHIVE_DIR . 'includes/class-importer.php';
require_once MAILERHIVE_DIR . 'includes/class-links.php';
require_once MAILERHIVE_DIR . 'includes/class-shell.php';
require_once MAILERHIVE_DIR . 'includes/class-mailerpress-bridge.php';
require_once MAILERHIVE_DIR . 'includes/class-email.php';
require_once MAILERHIVE_DIR . 'includes/licensing/class-client.php';
require_once MAILERHIVE_DIR . 'includes/licensing/class-secrets.php';
require_once MAILERHIVE_DIR . 'includes/licensing/class-license.php';
require_once MAILERHIVE_DIR . 'includes/licensing/class-updater.php';

// Optional modules, each off until enabled in Newsletter → Settings → Modules.
require_once MAILERHIVE_DIR . 'modules/consent-policy.php';
require_once MAILERHIVE_DIR . 'modules/fluentcart-lifecycle.php';
require_once MAILERHIVE_DIR . 'modules/rich-paste.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MAILERHIVE_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'mailerhive', 'MailerHive\\CLI' );
}

MailerHive\Settings::init();
MailerHive\Issues::init();
MailerHive\Links::init();
MailerHive\Shell::init();
MailerHive\MailerPress_Bridge::init();
MailerHive\Email::init();
( new MailerHive\Licensing\Updater() )->register();

register_activation_hook(
	__FILE__,
	static function (): void {
		MailerHive\Settings::seed();
		MailerHive\Issues::register();
		flush_rewrite_rules( false );
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( MailerHive\MailerPress_Bridge::SYNC_HOOK );
		flush_rewrite_rules( false );
	}
);
