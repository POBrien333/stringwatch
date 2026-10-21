<?php
// Runs when the plugin is deleted from the Plugins screen: remove everything it stored.
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

foreach (['trwatch_last_scan', 'trwatch_allowlist', 'trwatch_skipped', 'trwatch_languages', 'trwatch_ignore_selectors', 'trwatch_ignored_pages'] as $trwatch_option) {
    delete_option($trwatch_option);
}

// scans in progress (expire after an hour anyway)
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no API to delete transients by prefix
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_trwatch_run_') . '%',
    $wpdb->esc_like('_transient_timeout_trwatch_run_') . '%'
));
