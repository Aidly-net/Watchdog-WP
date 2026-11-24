<?php

namespace WebsiteWatchdog\Agent\Cron;

use WebsiteWatchdog\Agent\Http\Client;

class Heartbeat
{
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'register_schedule']);

        if (!wp_next_scheduled('website_watchdog_retry_queue')) {
            wp_schedule_event(time(), '15minutes', 'website_watchdog_retry_queue');
        }

        if (!wp_next_scheduled('website_watchdog_heartbeat')) {
            wp_schedule_event(time(), '15minutes', 'website_watchdog_heartbeat');
        }

        add_action('website_watchdog_retry_queue', [$this, 'run_queue_flush']);
        add_action('website_watchdog_heartbeat', [$this, 'send_ping']);
        add_action('website_watchdog_flush_local', [$this, 'flush_local']);
    }

    public function register_schedule(array $schedules): array
    {
        if (!isset($schedules['15minutes'])) {
            $schedules['15minutes'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 Minutes', 'website-watchdog'),
            ];
        }

        return $schedules;
    }

    public function run_queue_flush(): void
    {
        $queue = new \WebsiteWatchdog\Agent\Http\Queue();
        $queue->flush();
        $store = new \WebsiteWatchdog\Agent\Local\Store();
        $store->cleanup();
        $store->flush_outbox();
    }

    public function flush_local(): void
    {
        (new \WebsiteWatchdog\Agent\Local\Store())->flush_outbox();
    }

    public function send_ping(): void
    {
        $client = new Client();
        $client->dispatch([
            'event_type' => 'site_ping',
            'timestamp' => gmdate('c'),
            'payload' => [
                'agent_version' => WEBSITE_WATCHDOG_VERSION,
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'site_url' => home_url(),
            ],
        ], false, '/' . WEBSITE_WATCHDOG_API_VERSION . '/sites/ping');
    }
}
