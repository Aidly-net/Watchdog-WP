<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Cron\Heartbeat;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WPState;

class HeartbeatTest extends TestCase
{
    public function test_register_schedules_events_and_hooks(): void
    {
        (new Heartbeat())->register();

        $this->assertArrayHasKey('website_watchdog_retry_queue', WPState::$scheduled);
        $this->assertArrayHasKey('website_watchdog_heartbeat', WPState::$scheduled);
        $this->assertArrayHasKey('cron_schedules', WPState::$filters);
        $this->assertArrayHasKey('website_watchdog_retry_queue', WPState::$actions);
        $this->assertArrayHasKey('website_watchdog_heartbeat', WPState::$actions);
    }

    public function test_register_does_not_reschedule_existing_events(): void
    {
        WPState::$scheduled['website_watchdog_heartbeat'] = 123;

        (new Heartbeat())->register();

        $this->assertSame(123, WPState::$scheduled['website_watchdog_heartbeat']);
    }

    public function test_register_schedule_adds_15_minute_interval_once(): void
    {
        $heartbeat = new Heartbeat();

        $schedules = $heartbeat->register_schedule([]);
        $this->assertSame(900, $schedules['15minutes']['interval']);

        $existing = ['15minutes' => ['interval' => 1, 'display' => 'custom']];
        $this->assertSame($existing, $heartbeat->register_schedule($existing));
    }

    public function test_send_ping_posts_site_info_to_ping_endpoint(): void
    {
        $this->configureVerifiedKey();

        (new Heartbeat())->send_ping();

        $call = WPState::$remote_calls[0];
        $this->assertSame('https://example.test/api/v1/sites/ping', $call['url']);
        $body = json_decode($call['args']['body'], true);
        $this->assertSame('site_ping', $body['event_type']);
        $this->assertSame(WEBSITE_WATCHDOG_VERSION, $body['payload']['agent_version']);
        $this->assertSame(PHP_VERSION, $body['payload']['php_version']);
        $this->assertSame('https://site.test', $body['payload']['site_url']);
    }

    public function test_run_queue_flush_delivers_queued_events(): void
    {
        $this->configureVerifiedKey();
        WPState::$options['website_watchdog_offline_queue'] = [['n' => 1]];
        WPState::$remote_handler = static function () {
            return ['response' => ['code' => 200], 'body' => '{"data":{"site_id":"site_1"}}'];
        };

        (new Heartbeat())->run_queue_flush();

        $this->assertArrayNotHasKey('website_watchdog_offline_queue', WPState::$options);
    }
}
