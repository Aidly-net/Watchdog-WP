<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\IncidentPayload;
use WebsiteWatchdog\Agent\Http\Client;
use WebsiteWatchdog\Agent\Http\PayloadLimiter;
use WebsiteWatchdog\Agent\Http\Queue;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WP_Error;
use WPState;

class ClientTest extends TestCase
{
    private function respond($response): void
    {
        WPState::$remote_handler = static function () use ($response) {
            return $response;
        };
    }

    public function test_check_key_rejects_empty_key_without_request(): void
    {
        $result = (new Client())->check_key('');

        $this->assertFalse($result['valid']);
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_check_key_valid(): void
    {
        $this->respond(['response' => ['code' => 200], 'body' => '{"data":{"site_id":"abc"}}']);

        $result = (new Client())->check_key('k');

        $this->assertTrue($result['valid']);
        $this->assertSame('abc', $result['site_id']);
        $this->assertSame('https://example.test/api/v1/sites/verify', WPState::$remote_calls[0]['url']);
        $this->assertSame('Bearer k', WPState::$remote_calls[0]['args']['headers']['Authorization']);
    }

    public function test_check_key_unauthorized(): void
    {
        foreach ([401, 403] as $code) {
            $this->respond(['response' => ['code' => $code], 'body' => '']);
            $this->assertFalse((new Client())->check_key('k')['valid']);
        }
    }

    public function test_check_key_server_error_is_unknown(): void
    {
        $this->respond(['response' => ['code' => 500], 'body' => '']);

        $this->assertNull((new Client())->check_key('k')['valid']);
    }

    public function test_check_key_network_error_is_unknown(): void
    {
        $this->respond(new WP_Error('http', 'timeout'));

        $result = (new Client())->check_key('k');

        $this->assertNull($result['valid']);
        $this->assertSame('timeout', $result['error']);
    }

    public function test_check_key_missing_site_id_is_invalid(): void
    {
        $this->respond(['response' => ['code' => 200], 'body' => '{"data":{}}']);

        $this->assertFalse((new Client())->check_key('k')['valid']);
    }

    public function test_verify_persists_valid_result(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        $this->respond(['response' => ['code' => 200], 'body' => '{"data":{"site_id":"abc"}}']);

        $this->assertTrue((new Client())->verify());
        $this->assertTrue(WPState::$options['website_watchdog_api_key_verified']);
        $this->assertSame('abc', WPState::$options['website_watchdog_site_id']);
    }

    public function test_verify_persists_rejection(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options['website_watchdog_api_key_verified'] = true;
        $this->respond(['response' => ['code' => 401], 'body' => '']);

        $this->assertFalse((new Client())->verify());
        $this->assertFalse(WPState::$options['website_watchdog_api_key_verified']);
    }

    public function test_verify_leaves_flag_unchanged_when_unreachable(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options['website_watchdog_api_key_verified'] = true;
        $this->respond(new WP_Error('http', 'down'));

        $this->assertNull((new Client())->verify());
        $this->assertTrue(WPState::$options['website_watchdog_api_key_verified']);
    }

    public function test_dispatch_without_api_key_does_nothing(): void
    {
        $this->assertFalse((new Client())->dispatch(['a' => 1]));
        $this->assertSame([], WPState::$remote_calls);
        $this->assertArrayNotHasKey('website_watchdog_offline_queue', WPState::$options);
    }

    public function test_dispatch_is_disabled_in_email_mode(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options['website_watchdog_api_key_verified'] = true;

        $this->assertFalse((new Client())->dispatch(['a' => 1]));
        $this->assertSame([], WPState::$remote_calls);
        $this->assertArrayNotHasKey('website_watchdog_offline_queue', WPState::$options);
    }

    public function test_email_mode_preserves_existing_cloud_queue_without_sending(): void
    {
        $queue = [['event_type' => 'state_change', 'payload' => ['action' => 'updated']]];
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options['website_watchdog_api_key_verified'] = true;
        WPState::$options['website_watchdog_offline_queue'] = $queue;

        $this->assertSame($queue, (new Queue())->flush());
        $this->assertSame($queue, WPState::$options['website_watchdog_offline_queue']);
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_dispatch_with_unverified_key_queues_event(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options['website_watchdog_notification_method'] = 'cloud';

        $this->assertFalse((new Client())->dispatch(['a' => 1]));
        $this->assertCount(1, WPState::$options['website_watchdog_offline_queue']);
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_dispatch_unverified_without_queueing(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options['website_watchdog_notification_method'] = 'cloud';

        (new Client())->dispatch(['a' => 1], false);

        $this->assertArrayNotHasKey('website_watchdog_offline_queue', WPState::$options);
    }

    public function test_dispatch_sends_sanitized_authenticated_request(): void
    {
        $this->configureVerifiedKey('k');

        $this->assertTrue((new Client())->dispatch(['message' => 'x', 'password' => 'p']));

        $call = WPState::$remote_calls[0];
        $this->assertSame('https://example.test/api/v1/events/ingest', $call['url']);
        $this->assertSame('Bearer k', $call['args']['headers']['Authorization']);
        $this->assertSame(['message' => 'x', 'password' => '[REDACTED]'], json_decode($call['args']['body'], true));
    }

    public function test_dispatch_custom_endpoint(): void
    {
        $this->configureVerifiedKey();

        (new Client())->dispatch(['a' => 1], false, '/v1/sites/ping');

        $this->assertSame('https://example.test/api/v1/sites/ping', WPState::$remote_calls[0]['url']);
    }

    public function test_dispatch_queues_on_transport_error(): void
    {
        $this->configureVerifiedKey();
        $this->respond(new WP_Error('http', 'fail'));

        $this->assertFalse((new Client())->dispatch(['a' => 1]));
        $this->assertCount(1, WPState::$options['website_watchdog_offline_queue']);
    }

    public function test_dispatch_does_not_queue_when_disabled(): void
    {
        $this->configureVerifiedKey();
        $this->respond(new WP_Error('http', 'fail'));

        $this->assertFalse((new Client())->dispatch(['a' => 1], false));
        $this->assertArrayNotHasKey('website_watchdog_offline_queue', WPState::$options);
    }

    public function test_dispatch_uses_blocking_short_timeout(): void
    {
        $this->configureVerifiedKey();

        (new Client())->dispatch(['a' => 1]);

        $args = WPState::$remote_calls[0]['args'];
        $this->assertSame(Client::SEND_TIMEOUT, $args['timeout']);
        $this->assertArrayNotHasKey('blocking', $args);
    }

    public function test_outage_triggers_backoff_and_skips_network(): void
    {
        $this->configureVerifiedKey();
        $this->respond(new WP_Error('http', 'down'));

        $client = new Client();
        for ($i = 1; $i <= 5; $i++) {
            $this->assertFalse($client->dispatch(['n' => $i]));
        }

        $this->assertCount(1, WPState::$remote_calls);
        $this->assertCount(5, WPState::$options['website_watchdog_offline_queue']);
        $this->assertNotEmpty(WPState::$transients['website_watchdog_backoff']);
    }

    public function test_server_error_queues_but_client_error_drops(): void
    {
        $this->configureVerifiedKey();
        $this->respond(['response' => ['code' => 422], 'body' => '']);

        $this->assertFalse((new Client())->dispatch(['a' => 1]));
        $this->assertArrayNotHasKey('website_watchdog_offline_queue', WPState::$options);

        $this->respond(['response' => ['code' => 503], 'body' => '']);

        $this->assertFalse((new Client())->dispatch(['a' => 2]));
        $this->assertCount(1, WPState::$options['website_watchdog_offline_queue']);
    }

    public function test_success_clears_backoff_state(): void
    {
        $this->configureVerifiedKey();
        WPState::$transients['website_watchdog_delivery_failures'] = 3;

        $this->assertTrue((new Client())->dispatch(['a' => 1]));
        $this->assertArrayNotHasKey('website_watchdog_delivery_failures', WPState::$transients);
    }

    public function test_legacy_incident_is_never_transmitted(): void
    {
        $this->configureVerifiedKey();
        $event = ['event_type' => 'incident', 'payload' => ['severity' => 'error', 'source' => 'a.php:1', 'details' => 'boom']];

        $client = new Client();
        $this->assertFalse($client->dispatch($event));
        $this->assertSame(Client::RESULT_DROP, $client->deliver($event));
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_unverified_legacy_incidents_are_not_queued(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options['website_watchdog_notification_method'] = 'cloud';
        $event = ['severity' => 'error', 'source' => 'cron', 'details' => 'private-canary'];

        $this->assertFalse((new Client())->dispatch($event, true, '/v1/incidents/on-demand'));
        $this->assertArrayNotHasKey('website_watchdog_offline_queue', WPState::$options);
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_local_delivery_rejects_diagnostics_at_network_boundary(): void
    {
        $this->configureVerifiedKey();
        $id = 'c505e5a5-07e0-4b0d-9d8e-26e044580fc0';
        $payload = [
            'event_id' => 'fa83b5df-c9ec-47dc-95fc-ab190da376ca', 'local_id' => $id,
            'revision' => 1, 'status' => 'new', 'mute_future' => false,
            'severity' => 'critical',
            'last_seen_at' => '2026-11-25T12:00:00+00:00',
            'incident_url' => 'https://site.test/wp-admin/admin.php?page=website-watchdog-settings&tab=incidents&incident_id=' . $id,
            'recent_changes' => [], 'notify' => true,
        ];
        $client = new Client();
        $this->assertSame(Client::RESULT_DROP, $client->deliver($payload + ['details' => 'private-canary'], '/v1/incidents/local'));
        $bad = $payload;
        $bad['incident_url'] .= '&page=private-canary';
        $this->assertSame(Client::RESULT_DROP, $client->deliver($bad, '/v1/incidents/local'));
        $bad = $payload;
        $bad['severity'] = 'private-canary';
        $this->assertSame(Client::RESULT_DROP, $client->deliver($bad, '/v1/incidents/local'));
        $this->assertSame([], WPState::$remote_calls);
        $this->assertSame(Client::RESULT_RETRY, $client->deliver($payload, '/v1/incidents/local'));
        $this->assertCount(1, WPState::$remote_calls);
        $this->respond(['response' => ['code' => 202], 'body' => '{"status":"queued"}']);
        $this->assertSame(Client::RESULT_OK, $client->deliver($payload, '/v1/incidents/local'));
        unset($payload['severity']);
        $this->assertSame(Client::RESULT_OK, $client->deliver($payload, '/v1/incidents/local'));
    }

    public function test_oversized_payload_is_truncated_or_dropped(): void
    {
        $this->configureVerifiedKey();

        $this->assertTrue((new Client())->dispatch(['details' => str_repeat('a', 100000)]));
        $this->assertLessThan(5000, strlen(WPState::$remote_calls[0]['args']['body']));

        WPState::$remote_calls = [];
        $huge = [];
        for ($i = 0; $i < 50; $i++) {
            $huge['k' . $i] = array_fill(0, 50, str_repeat('b', 2000));
        }

        $this->assertFalse((new Client())->dispatch($huge));
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_large_stack_trace_is_capped(): void
    {
        $this->configureVerifiedKey();
        $frames = array_fill(0, 30, str_repeat('x', 4000));
        $frames[0] = 'first frame ' . $frames[0];
        $frames[29] = 'last frame ' . $frames[29];
        $event = IncidentPayload::build('error', 'test.php:1', 'failure', ['stack_trace' => $frames]);

        $sent = PayloadLimiter::limit($event);
        $stack_trace = $sent['payload']['stack_trace'];
        $this->assertCount(16, $stack_trace);
        $this->assertSame('.........', $stack_trace[10]);
        $this->assertStringStartsWith('first frame', $stack_trace[0]);
        $this->assertStringStartsWith('last frame', $stack_trace[15]);
        $this->assertLessThanOrEqual(8192, strlen(implode("\n", $stack_trace)));
    }

    public function test_incident_fields_and_stack_are_capped_by_encoded_size(): void
    {
        $this->configureVerifiedKey();
        $emoji = "\xF0\x9F\x98\x80";
        $frames = array_fill(0, 30, str_repeat($emoji, 136));
        $event = IncidentPayload::build('error', str_repeat($emoji, 500), str_repeat('\\', 2000), [
            'url' => str_repeat('"', 2000),
            'error_type' => str_repeat($emoji, 1000),
            'stack_trace' => $frames,
        ]);

        $body = wp_json_encode(PayloadLimiter::limit($event));
        $this->assertLessThanOrEqual(32768, strlen($body));
        $sent = json_decode($body, true)['payload'];
        foreach (['source', 'url', 'error_type'] as $field) {
            $this->assertLessThanOrEqual(2002, strlen(wp_json_encode($sent[$field])));
            $this->assertStringEndsWith('...[truncated]', $sent[$field]);
        }
        $this->assertSame(str_repeat('\\', 2000), $sent['details']);
        $this->assertCount(16, $sent['stack_trace']);
        $this->assertSame('.........', $sent['stack_trace'][10]);
        $this->assertLessThanOrEqual(8192, strlen(wp_json_encode($sent['stack_trace'])));
        $this->assertStringEndsWith('...[truncated]', $sent['stack_trace'][0]);
        $this->assertStringEndsWith('...[truncated]', $sent['stack_trace'][15]);
    }

    public function test_automatic_state_change_uses_custom_event_limits(): void
    {
        $this->configureVerifiedKey();
        $details = [
            'first' => str_repeat('a', 5000),
            'too_large' => str_repeat('"', 20000),
            'last' => 'not retained',
        ];
        $event = [
            'event_type' => 'state_change',
            'timestamp' => gmdate('c'),
            'payload' => ['action' => str_repeat('a', 300), 'target' => str_repeat('é', 300), 'details' => $details],
        ];

        $this->assertTrue((new Client())->dispatch($event));

        $body = WPState::$remote_calls[0]['args']['body'];
        $sent = json_decode($body, true)['payload'];
        $this->assertLessThanOrEqual(32768, strlen($body));
        $this->assertSame(str_repeat('a', 255), $sent['action']);
        $this->assertSame(str_repeat('é', 255), $sent['target']);
        $this->assertSame(['first' => $details['first'], 'truncated' => true], $sent['details']);
    }

    public function test_automatic_incident_message_uses_remaining_json_budget(): void
    {
        $this->configureVerifiedKey();
        $message = str_repeat("\xF0\x9F\x98\x80", 12000);
        $event = IncidentPayload::build('error', 'file.php:1', $message, [
            'stack_trace' => array_fill(0, 20, str_repeat('frame', 150)),
        ]);

        $body = wp_json_encode(PayloadLimiter::limit($event));
        $sent = json_decode($body, true)['payload'];
        $this->assertLessThanOrEqual(32768, strlen($body));
        $this->assertGreaterThan(2000, strlen($sent['details']));
        $this->assertStringEndsWith('...[truncated]', $sent['details']);
        $this->assertSame('.........', $sent['stack_trace'][10]);
    }

    public function test_dispatch_blocked_by_circuit_breaker(): void
    {
        $this->configureVerifiedKey();
        WPState::$transients['website_watchdog_cooldown'] = true;

        $this->assertFalse((new Client())->dispatch(['a' => 1]));
        $this->assertSame([], WPState::$remote_calls);
    }
}
