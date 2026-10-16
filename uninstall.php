<?php
/**
 * Uninstall: remove MailerHive settings and scheduled events. Issues (posts) and
 * the consent policy option are kept, since they hold content and consent records.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mailerhive_settings' );
delete_option( 'mailerhive_last_sync' );
delete_option( 'mailerhive_flush_rewrites' );
delete_option( 'mailerhive_license' );
delete_transient( 'mailerhive_update_metadata' );
wp_clear_scheduled_hook( 'mailerhive_sync_campaigns' );
wp_clear_scheduled_hook( 'mailerhive_fc_abandoned_sweep' );
wp_clear_scheduled_hook( 'mailerhive_paid_sweep' );
// The mailerhive_members table is kept: it records what readers paid for.
