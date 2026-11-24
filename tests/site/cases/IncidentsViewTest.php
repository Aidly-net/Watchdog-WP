<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Admin\IncidentsView;
use WebsiteWatchdog\Agent\Local\Store;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class IncidentsViewTest extends TestCase
{
    public function test_empty_history_does_not_require_a_key(): void
    {
        delete_option('website_watchdog_api_key');
        $out = $this->capture([new IncidentsView(), 'render']);
        $this->assert_contains('No incident history available yet.', $out);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_local_list_and_detail_escape_untrusted_content(): void
    {
        $store = new Store();
        $this->assert_true($store->capture([
            'timestamp' => gmdate('c'),
            'payload' => ['source' => 'example.php', 'details' => '<script>alert(1)</script>', 'stack_trace' => ['<frame>']],
        ]));
        $incident = $store->list_incidents(1)[0];
        $out = $this->capture([new IncidentsView(), 'render']);
        $this->assert_contains('&lt;script&gt;', $out);
        $this->assert_not_contains('<script>alert(1)', $out);
        $this->set_request(['incident_id' => $incident['id']]);
        $out = $this->capture([new IncidentsView(), 'render']);
        $this->assert_contains('&lt;frame&gt;', $out);
        $this->assert_contains('example.php', $out);
        $this->assert_contains('name="revision"', $out);
    }

    public function test_expired_incident_is_reported(): void
    {
        $this->set_request(['incident_id' => 'c505e5a5-07e0-4b0d-9d8e-26e044580fc0']);
        $out = $this->capture([new IncidentsView(), 'render']);
        $this->assert_contains('Incident not found or expired.', $out);
    }

    public function test_status_update_requires_permission_and_nonce(): void
    {
        $this->as_visitor();
        $this->expect_die([new IncidentsView(), 'handle_status']);
        $this->as_admin();
        $this->set_request([], []);
        $this->expect_die([new IncidentsView(), 'handle_status']);
    }
}
