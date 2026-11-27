<?php

namespace WebsiteWatchdog\Agent;

class UsageTelemetry
{
    private const REPORT_HOOK = 'website_watchdog_report_usage';
    private const FORGET_HOOK = 'website_watchdog_forget_usage';

    public static function register(): void
    {
        add_filter('cron_schedules', [self::class, 'add_monthly_schedule']);
        add_action(self::REPORT_HOOK, [self::class, 'send_report']);
        add_action(self::FORGET_HOOK, [self::class, 'forget_report']);
        add_action('add_option_website_watchdog_usage_telemetry', [self::class, 'consent_added'], 10, 2);
        add_action('update_option_website_watchdog_usage_telemetry', [self::class, 'consent_updated'], 10, 2);

        if (get_option('website_watchdog_usage_telemetry', false) && !wp_next_scheduled(self::REPORT_HOOK)) {
            wp_schedule_event(time() + MONTH_IN_SECONDS, 'website_watchdog_monthly', self::REPORT_HOOK);
        } elseif (!get_option('website_watchdog_usage_telemetry', false)
            && get_option('website_watchdog_usage_installation_id', '')
            && !wp_next_scheduled(self::FORGET_HOOK)) {
            wp_schedule_single_event(time() + 5, self::FORGET_HOOK);
        }
    }

    public static function add_monthly_schedule(array $schedules): array
    {
        $schedules['website_watchdog_monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Every 30 days', 'website-watchdog'),
        ];

        return $schedules;
    }

    public static function set_consent(bool $consent): void
    {
        update_option('website_watchdog_usage_telemetry', $consent ? 1 : 0, false);
    }

    public static function consent_added($option, $value): void
    {
        self::schedule_consent((bool) $value, false);
    }

    public static function consent_updated($old_value, $value): void
    {
        self::schedule_consent((bool) $value, (bool) $old_value);
    }

    private static function schedule_consent(bool $consent, bool $was_consented): void
    {
        if ($consent) {
            wp_clear_scheduled_hook(self::FORGET_HOOK);
            if (!wp_next_scheduled(self::REPORT_HOOK)) {
                wp_schedule_event(time() + MONTH_IN_SECONDS, 'website_watchdog_monthly', self::REPORT_HOOK);
            }
            if (!$was_consented) {
                wp_schedule_single_event(time() + 5, self::REPORT_HOOK);
            }
            return;
        }

        wp_clear_scheduled_hook(self::REPORT_HOOK);
        if ($was_consented && get_option('website_watchdog_usage_installation_id', '')) {
            wp_schedule_single_event(time() + 5, self::FORGET_HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::REPORT_HOOK);
    }

    public static function build_report(): array
    {
        $installation_id = (string) get_option('website_watchdog_usage_installation_id', '');
        if ('' === $installation_id) {
            $installation_id = wp_generate_uuid4();
            update_option('website_watchdog_usage_installation_id', $installation_id, false);
        }

        return [
            'installation_id' => $installation_id,
            'wordpress_version' => sanitize_text_field((string) get_bloginfo('version')),
            'plugin_version' => WEBSITE_WATCHDOG_VERSION,
            'php_version' => PHP_VERSION,
            'cloud_connected' => 'cloud' === get_option('website_watchdog_notification_method', 'email')
                && '' !== (string) get_option('website_watchdog_api_key', '')
                && (bool) get_option('website_watchdog_api_key_verified', false),
        ];
    }

    public static function send_report(): void
    {
        if (!get_option('website_watchdog_usage_telemetry', false)) {
            return;
        }

        $response = wp_remote_post(self::endpoint(), [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(self::build_report()),
            'timeout' => 3,
            'redirection' => 0,
        ]);

        if (is_wp_error($response) || 200 > (int) wp_remote_retrieve_response_code($response)
            || 300 <= (int) wp_remote_retrieve_response_code($response)) {
            self::log_failure('Could not send anonymous usage report.');
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, self::REPORT_HOOK);
        }
    }

    public static function forget_report(): void
    {
        $installation_id = (string) get_option('website_watchdog_usage_installation_id', '');
        if ('' === $installation_id || get_option('website_watchdog_usage_telemetry', false)) {
            return;
        }

        $response = wp_remote_post(self::endpoint() . '/forget', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['installation_id' => $installation_id]),
            'timeout' => 3,
            'redirection' => 0,
        ]);

        if (is_wp_error($response) || 200 > (int) wp_remote_retrieve_response_code($response)
            || 300 <= (int) wp_remote_retrieve_response_code($response)) {
            self::log_failure('Could not delete anonymous usage report.');
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, self::FORGET_HOOK);
        }
    }

    private static function endpoint(): string
    {
        return rtrim(WEBSITE_WATCHDOG_API_URL, '/') . '/' . WEBSITE_WATCHDOG_API_VERSION . '/usage/anonymous';
    }

    private static function log_failure(string $message): void
    {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('Website Watchdog: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
