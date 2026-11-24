<?php

namespace WebsiteWatchdog\Agent;

use WebsiteWatchdog\Agent\Http\Client;
use WebsiteWatchdog\Agent\Local\Store;

class Api
{
    public static function send_plugin_state_event(string $event_type): void
    {
        if (!class_exists(Client::class)) {
            return;
        }

        $event = [
            'site_id' => get_option('website_watchdog_site_id', ''),
            'event_type' => 'state_change',
            'timestamp' => gmdate('c'),
            'payload' => [
                'action' => $event_type,
                'target' => 'Website Watchdog',
                'details' => ['plugin_slug' => 'website-watchdog'],
            ],
        ];
        if (!(new Store())->record_change($event, true)) {
            error_log('Website Watchdog could not record a local state change.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        (new Client())->dispatch($event);
    }

    /**
     * Send a custom state-change event to Website Watchdog (ARCHITECTURE.md 5.1).
     */
    public static function capture_event(string $action, array $details = [], ?string $target = null): bool
    {
        if (!class_exists(Client::class)) {
            return false;
        }

        if ('' === $action || null === $target || '' === $target
            || array_key_exists('truncated', $details)
            || !self::valid_event_details($details, true)) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Invalid custom event: action and target are required; details must be an associative array without a truncated key.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return false;
        }

        return (new Store())->record_change([
            'event_type' => 'state_change',
            'timestamp' => gmdate('c'),
            'payload' => [
                'action' => $action,
                'target' => $target,
                'details' => $details,
            ],
        ]);
    }

    private static function valid_event_details(array $details, bool $root = false): bool
    {
        foreach ($details as $key => $value) {
            if (($root && !is_string($key))
                || (is_array($value) && !self::valid_event_details($value))
                || (!is_array($value) && !is_scalar($value) && null !== $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Send a custom incident to Website Watchdog (ARCHITECTURE.md 5.5).
     */
    public static function capture_incident(string $details, string $severity = 'error', ?string $source = null): bool
    {
        if (!class_exists(Client::class)) {
            return false;
        }

        if (!in_array($severity, ['info', 'warning', 'error', 'critical'], true)) {
            $severity = 'info';
        }

        return (new Store())->capture(IncidentPayload::build($severity, $source ?? 'on_demand', $details));
    }
}
