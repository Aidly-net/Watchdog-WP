<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Api;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WPState;

class ApiTest extends TestCase
{
    private function lastBody(): array
    {
        $call = end(WPState::$remote_calls);

        return json_decode($call['args']['body'], true);
    }

    public function test_send_plugin_state_event(): void
    {
        $this->configureVerifiedKey();

        Api::send_plugin_state_event('plugin_activated');

        $body = $this->lastBody();
        $this->assertSame('state_change', $body['event_type']);
        $this->assertSame('site_1', $body['site_id']);
        $this->assertSame('plugin_activated', $body['payload']['action']);
        $this->assertSame('Website Watchdog', $body['payload']['target']);
    }

    public function test_capture_event_nests_details_and_target(): void
    {
        $this->configureVerifiedKey();

        $this->assertTrue(Api::capture_event('deploy', ['version' => '2.0', 'action' => 'detail', 'password' => 'secret'], 'app'));

        $payload = $this->lastBody()['payload'];
        $this->assertSame('deploy', $payload['action']);
        $this->assertSame(['version' => '2.0', 'action' => 'detail', 'password' => '[REDACTED]'], $payload['details']);
        $this->assertSame('app', $payload['target']);
    }

    public function test_capture_event_requires_target(): void
    {
        $this->configureVerifiedKey();

        $this->assertFalse(Api::capture_event('deploy'));
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_capture_event_caps_fields_and_preserves_utf8(): void
    {
        $this->configureVerifiedKey();

        $this->assertTrue(Api::capture_event(str_repeat('é', 300), [], str_repeat('x', 300)));

        $payload = $this->lastBody()['payload'];
        $this->assertSame(str_repeat('é', 255), $payload['action']);
        $this->assertSame(str_repeat('x', 255), $payload['target']);
        $this->assertSame([], $payload['details']);
        $this->assertStringContainsString('"details":{}', end(WPState::$remote_calls)['args']['body']);
    }

    public function test_capture_event_keeps_first_details_that_fit_encoded_size(): void
    {
        $this->configureVerifiedKey();
        $details = [
            'first' => str_repeat('é', 1000),
            'nested' => ['value' => str_repeat('\\', 12000)],
            'too_large' => str_repeat('"', 12000),
            'last' => 'not retained',
        ];

        $this->assertTrue(Api::capture_event('deploy', $details, 'app'));

        $body = end(WPState::$remote_calls)['args']['body'];
        $this->assertLessThanOrEqual(32768, strlen($body));
        $sent = json_decode($body, true)['payload']['details'];
        $this->assertSame(['first' => $details['first'], 'nested' => $details['nested'], 'truncated' => true], $sent);
    }

    public function test_capture_event_rejects_reserved_key_and_non_associative_details(): void
    {
        $this->configureVerifiedKey();

        $this->assertFalse(Api::capture_event('deploy', ['truncated' => false], 'app'));
        $this->assertFalse(Api::capture_event('deploy', ['first'], 'app'));
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_capture_event_keeps_details_over_previous_list_and_string_limits(): void
    {
        $this->configureVerifiedKey();
        $details = ['long' => str_repeat('x', 3000)];
        for ($index = 0; $index < 60; $index++) {
            $details['field_' . $index] = $index;
        }

        $this->assertTrue(Api::capture_event('deploy', $details, 'app'));
        $this->assertSame($details, $this->lastBody()['payload']['details']);
    }

    public function test_capture_event_respects_exact_encoded_size_boundary(): void
    {
        $this->configureVerifiedKey();
        $empty = [
            'site_id' => 'site_1',
            'event_type' => 'state_change',
            'timestamp' => gmdate('c'),
            'payload' => ['action' => 'deploy', 'target' => 'app', 'details' => ['data' => '']],
        ];
        $max_value_size = 32768 - strlen(wp_json_encode($empty));

        $this->assertTrue(Api::capture_event('deploy', ['data' => str_repeat('x', $max_value_size)], 'app'));
        $this->assertSame(32768, strlen(end(WPState::$remote_calls)['args']['body']));
        $this->assertSame(str_repeat('x', $max_value_size), $this->lastBody()['payload']['details']['data']);

        $this->assertTrue(Api::capture_event('deploy', ['data' => str_repeat('x', $max_value_size + 1)], 'app'));
        $this->assertSame(['truncated' => true], $this->lastBody()['payload']['details']);
    }

    public function test_capture_event_returns_false_without_key(): void
    {
        $this->assertFalse(Api::capture_event('deploy', [], 'app'));
    }

    public function test_capture_incident_posts_to_on_demand_endpoint(): void
    {
        $this->configureVerifiedKey();

        $this->assertTrue(Api::capture_incident('boom', 'critical', 'cron'));

        $this->assertSame('https://example.test/api/v1/incidents/on-demand', end(WPState::$remote_calls)['url']);
        $body = $this->lastBody();
        $this->assertSame('critical', $body['severity']);
        $this->assertSame('cron', $body['source']);
        $this->assertSame('boom', $body['details']);
    }

    public function test_capture_incident_defaults_and_invalid_severity(): void
    {
        $this->configureVerifiedKey();

        Api::capture_incident('x');
        $this->assertSame('error', $this->lastBody()['severity']);
        $this->assertSame('on_demand', $this->lastBody()['source']);

        Api::capture_incident('x', 'bogus');
        $this->assertSame('info', $this->lastBody()['severity']);
    }

    public function test_on_demand_incident_uses_remaining_json_budget(): void
    {
        $this->configureVerifiedKey();
        $details = str_repeat('"', 20000);

        $this->assertTrue(Api::capture_incident($details, 'error', 'cron'));

        $body = end(WPState::$remote_calls)['args']['body'];
        $this->assertLessThanOrEqual(32768, strlen($body));
        $this->assertGreaterThan(2000, strlen(json_decode($body, true)['details']));
        $this->assertStringEndsWith('...[truncated]', json_decode($body, true)['details']);
    }

    public function test_on_demand_incident_respects_exact_json_size_boundary(): void
    {
        $this->configureVerifiedKey();
        $empty = [
            'site_id' => 'site_1',
            'timestamp' => gmdate('c'),
            'source' => 'cron',
            'severity' => 'error',
            'details' => '',
        ];
        $max_value_size = 32768 - strlen(wp_json_encode($empty));

        $this->assertTrue(Api::capture_incident(str_repeat('x', $max_value_size), 'error', 'cron'));
        $this->assertSame(32768, strlen(end(WPState::$remote_calls)['args']['body']));
        $this->assertSame(str_repeat('x', $max_value_size), $this->lastBody()['details']);

        $this->assertTrue(Api::capture_incident(str_repeat('x', $max_value_size + 1), 'error', 'cron'));
        $this->assertSame(32768, strlen(end(WPState::$remote_calls)['args']['body']));
        $this->assertStringEndsWith('...[truncated]', $this->lastBody()['details']);
    }
}
