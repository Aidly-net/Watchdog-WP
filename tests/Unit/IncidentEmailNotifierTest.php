<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Local\IncidentEmailNotifier;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WPState;

class IncidentEmailNotifierTest extends TestCase
{
    public function test_sends_only_minimal_incident_information(): void
    {
        WPState::$options['website_watchdog_notification_email'] = 'alerts@example.com';
        $incident = [
            'id' => '12345678-1234-1234-1234-123456789abc',
            'payload' => wp_json_encode([
                'severity' => 'critical',
                'source' => 'private-source.php',
                'details' => 'private diagnostic details',
            ]),
        ];

        $this->assertTrue((new IncidentEmailNotifier())->send($incident));
        $this->assertCount(1, WPState::$mail_calls);
        $this->assertSame('alerts@example.com', WPState::$mail_calls[0]['to']);
        $this->assertStringContainsString('Severity: critical', WPState::$mail_calls[0]['message']);
        $this->assertStringContainsString('incident_id=12345678-1234-1234-1234-123456789abc', WPState::$mail_calls[0]['message']);
        $this->assertStringNotContainsString('private-source.php', WPState::$mail_calls[0]['message']);
        $this->assertStringNotContainsString('private diagnostic details', WPState::$mail_calls[0]['message']);
    }

    public function test_does_not_send_with_invalid_recipient_or_in_cloud_mode(): void
    {
        WPState::$options['website_watchdog_notification_email'] = 'not-an-email';
        $incident = ['id' => '12345678-1234-1234-1234-123456789abc', 'payload' => '{}'];

        $this->assertFalse((new IncidentEmailNotifier())->send($incident));
        WPState::$options['website_watchdog_notification_email'] = 'alerts@example.com';
        WPState::$options['website_watchdog_notification_method'] = 'slack_webhook';
        $this->assertFalse((new IncidentEmailNotifier())->send($incident));
        $this->assertSame([], WPState::$mail_calls);
    }
}
