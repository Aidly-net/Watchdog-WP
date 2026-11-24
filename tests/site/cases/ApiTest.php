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

        $payload = $this->last_local_payload('changes');
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

        $this->assert_same('[REDACTED]', $this->last_local_payload('changes')['details']['password']);
    }

    public function test_capture_event_succeeds_without_api_key(): void
    {
        delete_option('website_watchdog_api_key');

        $this->assert_true(Api::capture_event('deploy', [], 'app'));
    }

    public function test_capture_incident_blocks_legacy_endpoint(): void
    {
        $this->configure_verified_key();

        $this->assert_true(Api::capture_incident('boom', 'critical', 'cron'));
        $this->assert_same('boom', $this->last_local_payload('incidents')['details']);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_global_helper_functions_delegate_to_api(): void
    {
        $this->configure_verified_key();

        $this->assert_true(website_watchdog_capture_event('helper_event', [], 'target'));
        $this->assert_same('helper_event', $this->last_local_payload('changes')['action']);

        $this->assert_true(website_watchdog_capture_incident('helper incident', 'warning'));
    }
}
