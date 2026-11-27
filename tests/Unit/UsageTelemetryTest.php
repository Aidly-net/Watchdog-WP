<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Tests\TestCase;
use WebsiteWatchdog\Agent\UsageTelemetry;
use WebsiteWatchdog\Agent\Admin\Settings;
use WPState;

class UsageTelemetryTest extends TestCase
{
    public function test_report_contains_only_opted_in_anonymous_version_data(): void
    {
        WPState::$options['website_watchdog_usage_telemetry'] = 1;
        WPState::$options['website_watchdog_api_key'] = 'private-api-key';
        WPState::$options['website_watchdog_api_key_verified'] = true;
        WPState::$options['website_watchdog_notification_method'] = 'cloud';

        UsageTelemetry::send_report();

        $this->assertSame('https://example.test/api/v1/usage/anonymous', WPState::$remote_calls[0]['url']);
        $this->assertSame(['Content-Type' => 'application/json'], WPState::$remote_calls[0]['args']['headers']);
        $report = json_decode(WPState::$remote_calls[0]['args']['body'], true);
        $this->assertSame([
            'installation_id',
            'wordpress_version',
            'plugin_version',
            'php_version',
            'cloud_connected',
        ], array_keys($report));
        $this->assertSame('12345678-1234-1234-1234-123456789abc', $report['installation_id']);
        $this->assertTrue($report['cloud_connected']);
        $this->assertStringNotContainsString('private-api-key', WPState::$remote_calls[0]['args']['body']);
        $this->assertStringNotContainsString('site.test', WPState::$remote_calls[0]['args']['body']);
    }

    public function test_report_is_not_sent_without_consent(): void
    {
        UsageTelemetry::send_report();

        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_email_mode_is_not_reported_as_cloud_connected(): void
    {
        WPState::$options['website_watchdog_usage_telemetry'] = 1;
        WPState::$options['website_watchdog_api_key'] = 'private-api-key';
        WPState::$options['website_watchdog_api_key_verified'] = true;

        UsageTelemetry::send_report();

        $report = json_decode(WPState::$remote_calls[0]['args']['body'], true);
        $this->assertFalse($report['cloud_connected']);
    }

    public function test_withdrawing_consent_stops_reports_and_schedules_deletion(): void
    {
        WPState::$options['website_watchdog_usage_telemetry'] = 1;
        WPState::$options['website_watchdog_usage_installation_id'] = '12345678-1234-1234-1234-123456789abc';
        WPState::$scheduled['website_watchdog_report_usage'] = time();
        UsageTelemetry::register();

        UsageTelemetry::set_consent(false);

        $this->assertSame(0, WPState::$options['website_watchdog_usage_telemetry']);
        $this->assertArrayNotHasKey('website_watchdog_report_usage', WPState::$scheduled);
        $this->assertArrayHasKey('website_watchdog_forget_usage', WPState::$scheduled);
    }

    public function test_registered_sanitizer_does_not_reenter_and_consent_transitions_are_scheduled(): void
    {
        UsageTelemetry::register();
        add_filter('sanitize_option_website_watchdog_usage_telemetry', [(new Settings()), 'sanitize_usage_telemetry']);

        update_option('website_watchdog_usage_telemetry', '1');
        $this->assertSame(1, WPState::$options['website_watchdog_usage_telemetry']);
        $this->assertArrayHasKey('website_watchdog_report_usage', WPState::$scheduled);

        WPState::$options['website_watchdog_usage_installation_id'] = '12345678-1234-1234-1234-123456789abc';
        update_option('website_watchdog_usage_telemetry', '0');
        $this->assertSame(0, WPState::$options['website_watchdog_usage_telemetry']);
        $this->assertArrayNotHasKey('website_watchdog_report_usage', WPState::$scheduled);
        $this->assertArrayHasKey('website_watchdog_forget_usage', WPState::$scheduled);
    }
}
