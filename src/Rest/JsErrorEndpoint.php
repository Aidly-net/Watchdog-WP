<?php

namespace WebsiteWatchdog\Agent\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WebsiteWatchdog\Agent\Http\Client;
use WebsiteWatchdog\Agent\IncidentPayload;
use WebsiteWatchdog\Agent\Local\Store;

class JsErrorEndpoint
{
    private const MAX_BODY_BYTES = 16384;
    private const RATE_LIMIT = 10;
    private const RATE_WINDOW = 60;
    private const ALLOWED_FIELDS = ['type', 'message', 'source', 'line', 'column', 'stack', 'url'];
    private const SEEN_KEY = 'website_watchdog_js_seen';
    private const DEDUPE_WINDOW = 3600;
    private const MAX_SEEN = 100;

    protected $http_client;
    protected $store;

    public function __construct(?Client $http_client = null, ?Store $store = null)
    {
        $this->http_client = $http_client ?: new Client();
        $this->store = $store ?: new Store();
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('website-watchdog/' . WEBSITE_WATCHDOG_API_VERSION, '/js-error', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_request'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle_request(WP_REST_Request $request): WP_REST_Response
    {
        if (strlen((string) $request->get_body()) > self::MAX_BODY_BYTES) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Payload too large.'], 413);
        }

        $client_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $rate_key = 'website_watchdog_js_rate_' . md5($client_address);
        $requests = get_transient($rate_key);
        $requests = is_array($requests) ? $requests : [];
        $requests = array_values(array_filter($requests, static function ($timestamp): bool {
            return is_int($timestamp) && (time() - $timestamp) < self::RATE_WINDOW;
        }));
        if (count($requests) >= self::RATE_LIMIT) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Rate limit exceeded.'], 429);
        }
        $requests[] = time();
        set_transient($rate_key, $requests, self::RATE_WINDOW);

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            // sendBeacon posts strings as text/plain, so WP does not parse them as JSON.
            $payload = json_decode((string) $request->get_body(), true);
        }
        if (!is_array($payload)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
        }

        $payload = array_intersect_key($payload, array_flip(self::ALLOWED_FIELDS));
        foreach (['type', 'message', 'source', 'url'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = is_scalar($payload[$field]) ? sanitize_textarea_field((string) $payload[$field]) : '';
            }
        }
        foreach (['line', 'column'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = absint($payload[$field]);
            }
        }

        $message = isset($payload['message']) ? (string) $payload['message'] : '';
        $source = isset($payload['source']) ? (string) $payload['source'] : '';
        $line = isset($payload['line']) ? (int) $payload['line'] : 0;
        $column = isset($payload['column']) ? (int) $payload['column'] : 0;

        // The same error repeating (loops, every page view) is reported once per hour.
        $fingerprint = md5($message . '|' . $source . '|' . $line . '|' . $column);
        $seen = get_transient(self::SEEN_KEY);
        $seen = is_array($seen) ? $seen : [];
        $now = time();
        $seen = array_filter($seen, static function ($timestamp) use ($now): bool {
            return is_int($timestamp) && ($now - $timestamp) < self::DEDUPE_WINDOW;
        });
        if (isset($seen[$fingerprint])) {
            return new WP_REST_Response(['ok' => true, 'duplicate' => true], 200);
        }
        $stack = isset($payload['stack']) && is_scalar($payload['stack'])
            ? sanitize_textarea_field((string) $payload['stack'])
            : '';
        $stack_trace = '' !== $stack
            ? array_map('trim', explode("\n", $stack))
            : [];
        $location = '' !== $source ? $source . ($line > 0 ? ':' . $line : '') : 'javascript';

        $sent = $this->store->capture(IncidentPayload::build('warning', $location, $message, [
            'error_type' => isset($payload['type']) && '' !== $payload['type'] ? 'js_error:' . $payload['type'] : 'js_error',
            'stack_trace' => $stack_trace,
            'url' => isset($payload['url']) ? (string) $payload['url'] : '',
        ]));

        if (!$sent) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Incident reporting is unavailable.'], 503);
        }
        $seen[$fingerprint] = $now;
        set_transient(self::SEEN_KEY, array_slice($seen, -self::MAX_SEEN, null, true), self::DEDUPE_WINDOW);
        return new WP_REST_Response(['ok' => true], 200);
    }
}
