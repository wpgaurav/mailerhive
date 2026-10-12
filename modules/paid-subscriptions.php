<?php
/**
 * Module loader: paid newsletter subscriptions sold through FluentCart (Settings → Modules).
 */

defined( 'ABSPATH' ) || exit;

if ( MailerHive\Settings::on( 'module_paid' ) ) {
	require_once __DIR__ . '/src/class-paid-plans.php';
	require_once __DIR__ . '/src/class-paid-members.php';
	require_once __DIR__ . '/src/class-paid-access.php';
	require_once __DIR__ . '/src/class-paid-delivery.php';
	require_once __DIR__ . '/src/class-paid-admin.php';
	require_once __DIR__ . '/src/class-paid-products.php';
	require_once __DIR__ . '/src/class-paid-visibility.php';

	MailerHive\Modules\Paid\Members::boot();
	MailerHive\Modules\Paid\Access::boot();
	MailerHive\Modules\Paid\Delivery::boot();
	MailerHive\Modules\Paid\Admin::boot();
	MailerHive\Modules\Paid\Visibility::boot();
	add_action( 'mailerhive_paid_reconcile_all', array( 'MailerHive\\Modules\\Paid\\Members', 'reconcile_all' ) );
}
