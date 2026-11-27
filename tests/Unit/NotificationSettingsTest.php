<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Admin\Settings;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WebsiteWatchdog\Agent\UsageTelemetry;
use WPState;

class NotificationSettingsTest extends TestCase
{
    public function test_usage_consent_is_not_hidden_by_notification_method(): void
    {
        (new Settings())->register_settings();

        $this->assertArrayHasKey('website_watchdog_usage_telemetry', WPState::$settings_fields);
        $this->assertArrayNotHasKey('class', WPState::$settings_fields['website_watchdog_usage_telemetry']);
        $this->assertSame('watchdog-cloud-only', WPState::$settings_fields['website_watchdog_api_key']['class']);
    }

    public function test_notification_method_accepts_only_supported_modes(): void
    {
        $settings = new Settings();

        $this->assertSame('email', $settings->sanitize_notification_method('email'));
        $this->assertSame('cloud', $settings->sanitize_notification_method('cloud'));
        $this->assertSame('email', $settings->sanitize_notification_method('slack_webhook'));
        $this->assertSame('email', $settings->sanitize_notification_method('unsupported'));
        $this->assertCount(2, WPState::$settings_errors);
    }

    public function test_email_recipient_is_required_and_validated_in_email_mode(): void
    {
        $settings = new Settings();
        $_POST['website_watchdog_notification_method'] = 'email';

        $this->assertSame('alerts@example.com', $settings->sanitize_notification_email(' alerts@example.com '));
        $this->assertSame('', $settings->sanitize_notification_email('invalid'));
        $this->assertSame('', $settings->sanitize_notification_email(''));
        $this->assertCount(2, WPState::$settings_errors);
        unset($_POST['website_watchdog_notification_method']);
    }

    public function test_omitted_inactive_fields_preserve_their_values(): void
    {
        $settings = new Settings();
        WPState::$options['website_watchdog_api_key'] = 'existing-key';
        WPState::$options['website_watchdog_usage_telemetry'] = 1;
        WPState::$options['website_watchdog_notification_email'] = 'alerts@example.com';
        $_POST['option_page'] = 'website_watchdog_settings_group';

        $this->assertSame('existing-key', $settings->sanitize_api_key(null));
        $this->assertSame(1, $settings->sanitize_usage_telemetry(null));
        $this->assertSame('alerts@example.com', $settings->sanitize_notification_email(null));
        $this->assertSame('existing-key', $settings->validate_api_key_option('existing-key', 'existing-key', 'website_watchdog_api_key'));
        $this->assertSame([], WPState::$remote_calls);
        $this->assertSame([], WPState::$scheduled);
    }

    public function test_saving_email_with_disabled_cloud_fields_preserves_cloud_settings_and_consent(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'existing-key';
        WPState::$options['website_watchdog_usage_telemetry'] = 1;
        UsageTelemetry::register();
        $settings = new Settings();
        add_filter('sanitize_option_website_watchdog_notification_email', [$settings, 'sanitize_notification_email']);
        add_filter('sanitize_option_website_watchdog_api_key', [$settings, 'sanitize_api_key']);
        add_filter('sanitize_option_website_watchdog_usage_telemetry', [$settings, 'sanitize_usage_telemetry']);
        $_POST['website_watchdog_notification_method'] = 'email';

        update_option('website_watchdog_notification_email', 'alerts@example.com');
        update_option('website_watchdog_api_key', null);
        update_option('website_watchdog_usage_telemetry', null);

        $this->assertSame('alerts@example.com', WPState::$options['website_watchdog_notification_email']);
        $this->assertSame('existing-key', WPState::$options['website_watchdog_api_key']);
        $this->assertSame(1, WPState::$options['website_watchdog_usage_telemetry']);
        $this->assertArrayNotHasKey('website_watchdog_forget_usage', WPState::$scheduled);
        $this->assertSame([], WPState::$remote_calls);
    }
}
