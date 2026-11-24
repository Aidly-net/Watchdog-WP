<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Api;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WPState;

class ApiTest extends TestCase
{
    public function test_plugin_state_is_recorded_and_sent(): void
    {
        $this->configureVerifiedKey();
        Api::send_plugin_state_event('plugin_activated');

        $this->assertTrue(WPState::$local_changes[0]['shareable']);
        $body = json_decode(WPState::$remote_calls[0]['args']['body'], true);
        $this->assertSame('plugin_activated', $body['payload']['action']);
    }

    public function test_custom_event_stays_local_without_a_key(): void
    {
        $this->assertTrue(Api::capture_event('plugin_updated', ['password' => 'secret'], 'private target'));
        $change = WPState::$local_changes[0];
        $this->assertFalse($change['shareable']);
        $this->assertSame('private target', $change['event']['payload']['target']);
        $this->assertSame('[REDACTED]', $change['event']['payload']['details']['password']);
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_invalid_custom_event_is_rejected(): void
    {
        $this->assertFalse(Api::capture_event('deploy'));
        $this->assertFalse(Api::capture_event('deploy', ['truncated' => false], 'app'));
        $this->assertFalse(Api::capture_event('deploy', ['first'], 'app'));
        $this->assertSame([], WPState::$local_changes);
    }

    public function test_incident_is_stored_locally_without_a_key(): void
    {
        $this->assertTrue(Api::capture_incident('private message', 'critical', 'cron'));
        $this->assertSame('private message', WPState::$local_incidents[0]['payload']['details']);
        $this->assertSame([], WPState::$remote_calls);
    }
}
