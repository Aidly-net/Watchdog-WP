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
    ];

    foreach ($options as $option) {
        delete_option($option);
    }

    delete_transient('website_watchdog_cached_incidents');
    delete_transient('website_watchdog_js_seen');
    delete_transient('website_watchdog_backoff');
    delete_transient('website_watchdog_delivery_failures');
    delete_transient('website_watchdog_event_seen');

    $transient_prefixes = [
        '_transient_website_watchdog_cached_incidents_',
        '_transient_timeout_website_watchdog_cached_incidents_',
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

    foreach (['website_watchdog_retry_queue', 'website_watchdog_heartbeat'] as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

website_watchdog_uninstall();
