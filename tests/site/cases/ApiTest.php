<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Api;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class ApiTest extends TestCase
{
    public function test_send_plugin_state_event(): void
    {
        $this->configure_verified_key();

        Api::send_plugin_state_event('plugin_activated');

        $body = $this->last_http_json();
        $this->assert_same('state_change', $body['event_type']);
        $this->assert_same('selftest-site', $body['site_id']);
        $this->assert_same('plugin_activated', $body['payload']['action']);
        $this->assert_same('Website Watchdog', $body['payload']['target']);
        $this->assert_same('website-watchdog', $body['payload']['details']['plugin_slug']);
    }

    public function test_capture_event_nests_details_and_target(): void
    {
        $this->configure_verified_key();

        $this->assert_true(Api::capture_event('deploy', ['version' => '2.0'], 'app'));

        $payload = $this->last_http_json()['payload'];
        $this->assert_same('deploy', $payload['action']);
        $this->assert_same('2.0', $payload['details']['version']);
        $this->assert_same('app', $payload['target']);
    }

    public function test_capture_event_requires_target(): void
    {
        $this->configure_verified_key();

        $this->assert_false(Api::capture_event('deploy'));
    }

    public function test_capture_event_redacts_sensitive_details(): void
    {
        $this->configure_verified_key();

        Api::capture_event('login', ['password' => 'hunter2'], 'app');

        $this->assert_same('[REDACTED]', $this->last_http_json()['payload']['details']['password']);
    }

    public function test_capture_event_returns_false_without_api_key(): void
    {
        delete_option('website_watchdog_api_key');

        $this->assert_false(Api::capture_event('deploy', [], 'app'));
    }

    public function test_capture_incident_posts_to_on_demand_endpoint(): void
    {
        $this->configure_verified_key();

        $this->assert_true(Api::capture_incident('boom', 'critical', 'cron'));

        $this->assert_same(WEBSITE_WATCHDOG_API_URL . '/' . WEBSITE_WATCHDOG_API_VERSION . '/incidents/on-demand', $this->last_http_call()['url']);
        $body = $this->last_http_json();
        $this->assert_same('critical', $body['severity']);
        $this->assert_same('cron', $body['source']);
        $this->assert_same('boom', $body['details']);
        $this->assert_same('selftest-site', $body['site_id']);
    }

    public function test_capture_incident_defaults(): void
    {
        $this->configure_verified_key();

        Api::capture_incident('x');

        $this->assert_same('error', $this->last_http_json()['severity']);
        $this->assert_same('on_demand', $this->last_http_json()['source']);
    }

    public function test_capture_incident_normalizes_invalid_severity(): void
    {
        $this->configure_verified_key();

        Api::capture_incident('x', 'bogus');

        $this->assert_same('info', $this->last_http_json()['severity']);
    }

    public function test_global_helper_functions_delegate_to_api(): void
    {
        $this->configure_verified_key();

        $this->assert_true(website_watchdog_capture_event('helper_event', [], 'target'));
        $this->assert_same('helper_event', $this->last_http_json()['payload']['action']);

        $this->assert_true(website_watchdog_capture_incident('helper incident', 'warning'));
        $this->assert_same('warning', $this->last_http_json()['severity']);
    }
}
