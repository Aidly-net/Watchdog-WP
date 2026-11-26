<?php

namespace WebsiteWatchdog\Agent\Http;

use WebsiteWatchdog\Agent\CircuitBreaker;
use WebsiteWatchdog\Agent\Sanitizer;

class Client
{
    public const SEND_TIMEOUT = 2;
    public const VERIFY_TIMEOUT = 3;

    public const RESULT_OK = 'ok';
    public const RESULT_RETRY = 'retry';
    public const RESULT_DROP = 'drop';
    public const RESULT_UNAUTHORIZED = 'unauthorized';

    /**
     * Side-effect-free key check against /sites/verify.
     *
     * 'valid' is true if accepted, false if rejected (401/403 or no site_id), null if the server was unreachable or errored.
     *
     * @return array{valid: ?bool, site_id: string, error: string}
     */
    public function check_key(string $api_key): array
    {
        if ('' === $api_key) {
            return ['valid' => false, 'site_id' => '', 'error' => 'Empty API key'];
        }

        $version_path = '/' . WEBSITE_WATCHDOG_API_VERSION;
        $url = rtrim(WEBSITE_WATCHDOG_API_URL, '/') . (false !== strpos(WEBSITE_WATCHDOG_API_URL, $version_path) ? '' : $version_path) . '/sites/verify';

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode(['site_url' => home_url()]),
            'timeout' => self::VERIFY_TIMEOUT,
            'redirection' => 0,
        ]);

        if (is_wp_error($response)) {
            return ['valid' => null, 'site_id' => '', 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (401 === $code || 403 === $code) {
            return ['valid' => false, 'site_id' => '', 'error' => 'HTTP ' . $code];
        }

        if (200 !== $code) {
            return ['valid' => null, 'site_id' => '', 'error' => 'HTTP ' . $code];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $site_id = isset($body['data']['site_id']) ? sanitize_text_field((string) $body['data']['site_id']) : '';

        return $site_id
            ? ['valid' => true, 'site_id' => $site_id, 'error' => '']
            : ['valid' => false, 'site_id' => '', 'error' => 'Missing site_id in response'];
    }

    /**
     * Verifies the stored key and persists the result. A null (unreachable) result leaves the flag unchanged.
     */
    public function verify(): ?bool
    {
        $result = $this->check_key((string) get_option('website_watchdog_api_key', ''));

        if (null !== $result['valid']) {
            update_option('website_watchdog_api_key_verified', $result['valid'], false);
            if ($result['valid']) {
                update_option('website_watchdog_site_id', $result['site_id']);
            } else {
                if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    error_log('Site API key rejected; event delivery paused. ' . $result['error']); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
            }
        }

        return $result['valid'];
    }

    public function dispatch(array $payload, bool $queue_on_failure = true, string $endpoint = '/' . WEBSITE_WATCHDOG_API_VERSION . '/events/ingest'): bool
    {
        if (!$this->cloud_delivery_enabled()) {
            return false;
        }
        if ($this->is_legacy_incident($payload, $endpoint)) {
            error_log('Website Watchdog blocked a legacy incident payload from leaving the site.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }
        $sanitized = PayloadLimiter::limit(Sanitizer::clean_payload($payload));
        if (null === $sanitized) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Event payload could not be encoded or exceeds the size limit.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return false;
        }

        if ((new EventDeduper())->is_duplicate($sanitized)) {
            return false;
        }

        $breaker = new CircuitBreaker();
        if (!$breaker->is_allowed()) {
            return false;
        }

        $api_key = get_option('website_watchdog_api_key', '');
        if ('' === $api_key) {
            return false;
        }

        // Unverified key: keep the event; Queue::flush() re-verifies before retrying.
        if (!get_option('website_watchdog_api_key_verified', false)) {
            if ($queue_on_failure) {
                (new Queue())->add($sanitized);
            }

            return false;
        }

        // During an outage, skip the network entirely and keep the event for the cron retry.
        if ((new DeliveryState())->is_backing_off()) {
            if ($queue_on_failure) {
                (new Queue())->add($sanitized);
            }

            return false;
        }

        $result = $this->deliver($sanitized, $endpoint);

        if (self::RESULT_RETRY === $result && $queue_on_failure) {
            (new Queue())->add($sanitized);
        }

        return self::RESULT_OK === $result;
    }

    /**
     * Single blocking delivery attempt with a short timeout; updates backoff state and returns a RESULT_* value.
     * RESULT_DROP means the backend rejected the payload itself (4xx), so retrying cannot help.
     */
    public function deliver(array $payload, string $endpoint = '/' . WEBSITE_WATCHDOG_API_VERSION . '/events/ingest'): string
    {
        if (!$this->cloud_delivery_enabled()) {
            return self::RESULT_DROP;
        }
        if ($this->is_legacy_incident($payload, $endpoint)) {
            error_log('Website Watchdog blocked a legacy incident payload from leaving the site.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return self::RESULT_DROP;
        }
        if ('/' . WEBSITE_WATCHDOG_API_VERSION . '/incidents/local' === $endpoint && !$this->is_minimal_incident($payload)) {
            error_log('Website Watchdog blocked an invalid local incident notification.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return self::RESULT_DROP;
        }
        $state = new DeliveryState();

        $response = wp_remote_post(WEBSITE_WATCHDOG_API_URL . $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . get_option('website_watchdog_api_key', ''),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => self::SEND_TIMEOUT,
            'redirection' => 0,
        ]);

        if (is_wp_error($response)) {
            $state->record_failure();

            return self::RESULT_RETRY;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code <= 299) {
            if ('/' . WEBSITE_WATCHDOG_API_VERSION . '/incidents/local' === $endpoint && 202 !== $code) {
                $state->record_failure();
                return self::RESULT_RETRY;
            }
            $state->record_success();

            return self::RESULT_OK;
        }

        if (401 === $code) {
            update_option('website_watchdog_api_key_verified', false, false);
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Unauthorized site API key; event delivery paused.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }

            return self::RESULT_UNAUTHORIZED;
        }

        if (429 === $code || $code >= 500 || $code < 400) {
            $state->record_failure();

            return self::RESULT_RETRY;
        }

        return self::RESULT_DROP;
    }

    private function cloud_delivery_enabled(): bool
    {
        return 'slack_webhook' === get_option('website_watchdog_notification_method', 'email');
    }

    private function is_legacy_incident(array $payload, string $endpoint): bool
    {
        return in_array($payload['event_type'] ?? '', ['incident', 'on_demand_incident'], true)
            || isset($payload['severity'], $payload['source'], $payload['details'])
            || false !== strpos($endpoint, '/incidents/on-demand');
    }

    private function is_minimal_incident(array $payload): bool
    {
        $fields = ['event_id', 'local_id', 'revision', 'status', 'mute_future', 'last_seen_at', 'incident_url', 'recent_changes', 'notify'];
        if (array_diff($fields, array_keys($payload)) || array_diff(array_keys($payload), array_merge($fields, ['severity']))
            || !is_string($payload['event_id']) || !is_string($payload['local_id'])
            || !is_int($payload['revision']) || !is_string($payload['status'])
            || !is_bool($payload['mute_future']) || !is_string($payload['last_seen_at'])
            || !is_string($payload['incident_url']) || !is_array($payload['recent_changes'])
            || !is_bool($payload['notify'])) {
            return false;
        }
        if (array_key_exists('severity', $payload) && !in_array($payload['severity'], ['info', 'warning', 'error', 'critical'], true)) {
            return false;
        }
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $timestamp = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/';
        if (!preg_match($uuid, $payload['event_id']) || !preg_match($uuid, $payload['local_id'])
            || $payload['revision'] < 1 || !in_array($payload['status'], ['new', 'investigating', 'resolved', 'reopened', 'muted'], true)
            || !preg_match($timestamp, $payload['last_seen_at']) || count($payload['recent_changes']) > 20) {
            return false;
        }
        $url = wp_parse_url($payload['incident_url']);
        if (!is_array($url) || !isset($url['scheme'], $url['host'], $url['path'], $url['query'])
            || !in_array($url['scheme'], ['http', 'https'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || substr($url['path'], -19) !== '/wp-admin/admin.php') {
            return false;
        }
        parse_str($url['query'], $query);
        if (count(explode('&', $url['query'])) !== 3 || count($query) !== 3 || ($query['page'] ?? null) !== 'website-watchdog-settings'
            || ($query['tab'] ?? null) !== 'incidents' || ($query['incident_id'] ?? null) !== $payload['local_id']) {
            return false;
        }
        foreach ($payload['recent_changes'] as $change) {
            if (!is_array($change) || array_diff(array_keys($change), ['action', 'target', 'timestamp', 'old_version', 'new_version'])
                || !isset($change['action'], $change['target'], $change['timestamp'])
                || !in_array($change['action'], ['plugin_updated', 'plugin_activated', 'plugin_deactivated', 'theme_updated', 'theme_switched'], true)) {
                return false;
            }
            foreach ($change as $value) {
                if (!is_string($value)) {
                    return false;
                }
            }
            if (!preg_match($timestamp, $change['timestamp'])) {
                return false;
            }
        }
        return true;
    }
}
