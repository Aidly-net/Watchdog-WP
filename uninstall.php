<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

function website_watchdog_uninstall(): void
{
    global $wpdb;

    $options = [
        'website_watchdog_api_key',
        'website_watchdog_api_key_verified',
        'website_watchdog_site_id',
        'website_watchdog_offline_queue',
        'website_watchdog_local_schema_version',
    ];

    foreach ($options as $option) {
        delete_option($option);
    }

    delete_transient('website_watchdog_js_seen');
    delete_transient('website_watchdog_backoff');
    delete_transient('website_watchdog_delivery_failures');
    delete_transient('website_watchdog_event_seen');

    $transient_prefixes = [
        '_transient_website_watchdog_js_rate_',
        '_transient_timeout_website_watchdog_js_rate_',
    ];

    foreach ($transient_prefixes as $prefix) {
        // One-off cleanup of paginated/rate-limit transients whose names are dynamic.
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            )
        );
    }

    foreach (['website_watchdog_retry_queue', 'website_watchdog_heartbeat', 'website_watchdog_flush_local'] as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    foreach (['watchdog_incidents', 'watchdog_changes', 'watchdog_outbox'] as $table) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }
}

if (is_multisite()) {
    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        website_watchdog_uninstall();
        restore_current_blog();
    }
} else {
    website_watchdog_uninstall();
}
