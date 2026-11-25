<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Local\Store;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class LocalStoreTest extends TestCase
{
    public function test_event_history_includes_custom_and_wordpress_changes_in_order(): void
    {
        $store = new Store();
        $now = time();
        for ($i = 0; $i < 21; $i++) {
            $this->assert_true($store->record_change([
                'timestamp' => gmdate('c', $now + $i),
                'payload' => ['action' => 'event_' . $i, 'target' => 'Example', 'details' => ['value' => $i]],
            ], 20 === $i));
        }
        $this->assert_same(21, $store->count_events());
        $first = $store->list_events(1);
        $second = $store->list_events(2);
        $this->assert_count(20, $first);
        $this->assert_count(1, $second);
        $this->assert_same('event_20', json_decode($first[0]['payload'], true)['action']);
        $this->assert_same('event_0', json_decode($second[0]['payload'], true)['action']);
        $this->assert_same($first[0], $store->get_event((int) $first[0]['id']));
        $this->assert_same(null, $store->get_event(0));
    }

    public function test_private_custom_changes_never_enter_outbox(): void
    {
        global $wpdb;
        $store = new Store();
        $timestamp = gmdate('c');
        $this->assert_true($store->record_change([
            'timestamp' => $timestamp,
            'payload' => ['action' => 'plugin_updated', 'target' => 'PRIVATE_CUSTOM_CANARY'],
        ]));
        $this->assert_true($store->record_change([
            'timestamp' => $timestamp,
            'payload' => ['action' => 'plugin_updated', 'target' => 'Approved Plugin', 'new_version' => '2.0'],
        ], true));
        $this->assert_true($store->capture([
            'timestamp' => $timestamp,
            'payload' => ['source' => 'plugins/example/file.php', 'details' => 'PRIVATE_INCIDENT_CANARY'],
        ]));
        $json = $wpdb->get_var('SELECT payload FROM ' . $wpdb->prefix . 'watchdog_outbox');
        $payload = json_decode($json, true);
        $this->assert_not_contains('PRIVATE_', $json);
        $this->assert_count(1, $payload['recent_changes']);
        $this->assert_same('Approved Plugin', $payload['recent_changes'][0]['target']);
        $this->assert_same('2.0', $payload['recent_changes'][0]['new_version']);
    }

    public function test_severity_is_sent_and_changes_sync_without_an_extra_alert(): void
    {
        global $wpdb;
        $store = new Store();
        $event = [
            'timestamp' => gmdate('c'),
            'payload' => ['severity' => 'warning', 'source' => 'PRIVATE_SOURCE', 'details' => 'PRIVATE_DETAILS'],
        ];
        $this->assert_true($store->capture($event));
        $table = $wpdb->prefix . 'watchdog_outbox';
        $first = json_decode($wpdb->get_var("SELECT payload FROM $table ORDER BY id ASC LIMIT 1"), true);
        $this->assert_same('warning', $first['severity']);
        $this->assert_true($first['notify']);
        $this->assert_not_contains('PRIVATE_', wp_json_encode($first));
        $this->assert_true($store->capture($event));
        $this->assert_same('1', $wpdb->get_var("SELECT COUNT(*) FROM $table"));

        $event['payload']['severity'] = 'critical';
        $this->assert_true($store->capture($event));
        $second = json_decode($wpdb->get_var("SELECT payload FROM $table ORDER BY id DESC LIMIT 1"), true);
        $this->assert_same('critical', $second['severity']);
        $this->assert_false($second['notify']);
        $this->assert_same(2, $second['revision']);
        $this->assert_same('ok', $store->update_status($second['local_id'], 2, 'resolved', false));

        $event['payload']['severity'] = 'error';
        $this->assert_true($store->capture($event));
        $reopened = json_decode($wpdb->get_var("SELECT payload FROM $table ORDER BY id DESC LIMIT 1"), true);
        $this->assert_same('error', $reopened['severity']);
        $this->assert_true($reopened['notify']);
        $this->assert_same('reopened', $reopened['status']);
        $this->assert_not_contains('PRIVATE_', wp_json_encode($reopened));
        $event['payload']['severity'] = 'PRIVATE_INVALID';
        $this->assert_false($store->capture($event));
    }

    public function test_grouping_mute_and_revision_conflicts(): void
    {
        global $wpdb;
        $store = new Store();
        $event = [
            'timestamp' => gmdate('c'),
            'payload' => ['source' => 'plugin.php:1', 'details' => 'example error'],
        ];
        $this->assert_true($store->capture($event));
        $row = $store->list_incidents(1)[0];
        $this->assert_true($store->capture($event));
        $this->assert_same('2', $store->get_incident($row['id'])['occurrences']);
        $this->assert_same('ok', $store->update_status($row['id'], 1, 'muted', true));
        $this->assert_true($store->capture($event));
        $this->assert_same('muted', $store->get_incident($row['id'])['status']);
        $this->assert_same('conflict', $store->update_status($row['id'], 1, 'resolved', false));
        $this->assert_same('ok', $store->update_status($row['id'], 2, 'muted', false));
        $this->assert_true($store->capture($event));
        $this->assert_same('reopened', $store->get_incident($row['id'])['status']);
        $this->assert_same('4', $store->get_incident($row['id'])['revision']);
        $this->assert_same('4', $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'watchdog_outbox'));
    }

    public function test_shared_change_text_fits_javascript_string_limits(): void
    {
        global $wpdb;
        $store = new Store();
        $this->assert_true($store->record_change([
            'timestamp' => gmdate('c'),
            'payload' => ['action' => 'plugin_updated', 'target' => str_repeat("\xF0\x9F\x98\x80", 200)],
        ], true));
        $this->assert_true($store->capture([
            'timestamp' => gmdate('c'),
            'payload' => ['source' => 'example.php', 'details' => 'error'],
        ]));
        $json = $wpdb->get_var('SELECT payload FROM ' . $wpdb->prefix . 'watchdog_outbox');
        $payload = json_decode($json, true);
        $this->assert_same(str_repeat("\xF0\x9F\x98\x80", 127), $payload['recent_changes'][0]['target']);
    }

    public function test_retention_and_correlation_window(): void
    {
        $store = new Store();
        $now = time();
        foreach ([-901, -900, 0, 1] as $offset) {
            $this->assert_true($store->record_change([
                'timestamp' => gmdate('c', $now + $offset),
                'payload' => ['action' => 'deploy', 'target' => (string) $offset],
            ]));
        }
        $this->assert_count(2, $store->recent_changes($now));
        $this->assert_true($store->capture([
            'timestamp' => gmdate('c', $now - 31 * DAY_IN_SECONDS),
            'payload' => ['source' => 'old.php', 'details' => 'old'],
        ]));
        $store->cleanup();
        $this->assert_count(0, $store->list_incidents(1));
    }

    public function test_outbox_retries_then_sends_only_minimal_data(): void
    {
        global $wpdb;
        $store = new Store();
        $this->configure_verified_key();
        $this->assert_true($store->capture([
            'timestamp' => gmdate('c'),
            'payload' => ['source' => 'PRIVATE_SOURCE_CANARY', 'details' => 'PRIVATE_MESSAGE_CANARY'],
        ]));
        $this->mock_http_error('offline');
        $store->flush_outbox();
        $table = $wpdb->prefix . 'watchdog_outbox';
        $this->assert_same('1', $wpdb->get_var("SELECT COUNT(*) FROM $table"));
        $this->mock_http(static function ($url) {
            return false !== strpos($url, '/sites/verify')
                ? \WebsiteWatchdog\Agent\SelfTest\Sandbox::response(200, '{"data":{"site_id":"selftest-site"}}')
                : \WebsiteWatchdog\Agent\SelfTest\Sandbox::response(202, '{"status":"queued"}');
        });
        $store->flush_outbox();
        $this->assert_same('0', $wpdb->get_var("SELECT COUNT(*) FROM $table"));
        $call = $this->last_http_call();
        $this->assert_contains('/incidents/local', $call['url']);
        $this->assert_not_contains('PRIVATE_', $call['args']['body']);
    }

    public function test_rest_status_requires_key_and_checks_revision(): void
    {
        $store = new Store();
        $this->configure_verified_key();
        $this->assert_true($store->capture([
            'timestamp' => gmdate('c'),
            'payload' => ['source' => 'example.php', 'details' => 'private'],
        ]));
        $incident = $store->list_incidents(1)[0];
        $route = '/website-watchdog/v1/incidents/' . $incident['id'] . '/status';
        $body = ['expected_revision' => 1, 'status' => 'resolved', 'mute_future' => false];
        $this->assert_status($this->rest('POST', $route, ['json' => $body]), 401);
        $options = ['json' => $body, 'headers' => ['Authorization' => 'Bearer selftest-key']];
        $this->assert_status($this->rest('POST', $route, $options), 200);
        $conflict = $this->rest('POST', $route, $options);
        $this->assert_status($conflict, 409);
        $this->assert_same('resolved', $conflict->get_data()['status']);
        $this->assert_not_contains('private', wp_json_encode($conflict->get_data()));
    }
}
