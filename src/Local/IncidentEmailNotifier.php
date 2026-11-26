<?php

namespace WebsiteWatchdog\Agent\Local;

class IncidentEmailNotifier
{
    public function send(array $incident): bool
    {
        if ('email' !== get_option('website_watchdog_notification_method', 'email')) {
            return false;
        }

        $recipient = sanitize_email((string) get_option('website_watchdog_notification_email', ''));
        if (!is_email($recipient)) {
            error_log('Website Watchdog could not send an incident alert: recipient e-mail address is invalid.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }

        $id = sanitize_text_field((string) ($incident['id'] ?? ''));
        if ('' === $id) {
            error_log('Website Watchdog could not send an incident alert: incident ID is missing.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }

        $payload = json_decode((string) ($incident['payload'] ?? ''), true);
        $severity = is_array($payload) && in_array($payload['severity'] ?? '', ['info', 'warning', 'error', 'critical'], true)
            ? $payload['severity']
            : 'unknown';
        $incident_url = add_query_arg([
            'page' => 'website-watchdog-settings',
            'tab' => 'incidents',
            'incident_id' => $id,
        ], admin_url('admin.php'));
        $message = sprintf(
            /* translators: 1: incident severity, 2: local WordPress incident link */
            __("A new incident was reported.\nSeverity: %1\$s\nReview incident: %2\$s", 'website-watchdog'),
            $severity,
            $incident_url
        );

        if (!wp_mail($recipient, __('Website Watchdog: New incident', 'website-watchdog'), $message)) {
            error_log('Website Watchdog could not send an incident alert using WordPress mail.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }

        return true;
    }
}
