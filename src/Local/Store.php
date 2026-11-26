<?php

namespace WebsiteWatchdog\Agent\Local;

use WebsiteWatchdog\Agent\Http\Client;
use WebsiteWatchdog\Agent\Sanitizer;

class Store
{
    private const VERSION = '2';
    private const ACTIONS = ['plugin_updated', 'plugin_activated', 'plugin_deactivated', 'theme_updated', 'theme_switched'];
    private const SEVERITIES = ['info', 'warning', 'error', 'critical'];

    public function install(): bool
    {
        global $wpdb;
        if (self::VERSION === get_option('website_watchdog_local_schema_version')) {
            return true;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $incidents = $wpdb->prefix . 'watchdog_incidents';
        $changes = $wpdb->prefix . 'watchdog_changes';
        $outbox = $wpdb->prefix . 'watchdog_outbox';
        dbDelta("CREATE TABLE $incidents (
            id char(36) NOT NULL,
            fingerprint char(64) NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL,
            mute_future tinyint NOT NULL DEFAULT 0,
            revision bigint unsigned NOT NULL DEFAULT 1,
            occurrences bigint unsigned NOT NULL DEFAULT 1,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY fingerprint (fingerprint),
            KEY last_seen (last_seen)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE $changes (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            occurred_at datetime NOT NULL,
            payload longtext NOT NULL,
            shareable tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY occurred_at (occurred_at)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE $outbox (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_id char(36) NOT NULL,
            payload longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id)
        ) ENGINE=InnoDB $charset;");
        foreach ([$incidents, $changes, $outbox] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                error_log('Website Watchdog could not create local storage.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                return false;
            }
        }
        if (!$wpdb->get_row("SHOW COLUMNS FROM $changes LIKE 'shareable'")) {
            error_log('Website Watchdog could not update local storage.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }
        update_option('website_watchdog_local_schema_version', self::VERSION, false);
        return true;
    }

    public function record_change(array $event, bool $shareable = false): bool
    {
        global $wpdb;
        if (!$this->install() || !isset($event['payload']) || !is_array($event['payload'])) {
            return false;
        }
        $time = strtotime((string) ($event['timestamp'] ?? ''));
        $json = wp_json_encode(Sanitizer::clean_payload($event['payload']));
        if (false === $time || false === $json) {
            error_log('Website Watchdog could not encode a local change.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }
        if (false === $wpdb->insert($wpdb->prefix . 'watchdog_changes', [
            'occurred_at' => gmdate('Y-m-d H:i:s', $time), 'payload' => $json, 'shareable' => $shareable ? 1 : 0,
        ], ['%s', '%s', '%d'])) {
            error_log('Website Watchdog could not save a local change.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }
        return true;
    }

    public function capture(array $event): bool
    {
        global $wpdb;
        if (!$this->install() || !isset($event['payload']) || !is_array($event['payload'])) {
            return false;
        }
        $payload = $event['payload'];
        $time = strtotime((string) ($event['timestamp'] ?? ''));
        $json = wp_json_encode($payload);
        if (false === $time || false === $json || !isset($payload['source'], $payload['details'])
            || '' === (string) $payload['source'] || '' === (string) $payload['details']
            || (isset($payload['severity']) && !in_array($payload['severity'], self::SEVERITIES, true))) {
            error_log('Website Watchdog could not encode a local incident.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }
        $fingerprint = hash('sha256', wp_json_encode([$payload['error_type'] ?? '', $payload['source'], $payload['details']]));
        $date = gmdate('Y-m-d H:i:s', $time);
        $table = $wpdb->prefix . 'watchdog_incidents';
        if (false === $wpdb->query('START TRANSACTION')) {
            return false;
        }
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (id, fingerprint, payload, status, first_seen, last_seen) VALUES (%s, %s, %s, 'new', %s, %s) ON DUPLICATE KEY UPDATE fingerprint = fingerprint",
            wp_generate_uuid4(), $fingerprint, $json, $date, $date
        ));
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE fingerprint = %s FOR UPDATE", $fingerprint), ARRAY_A);
        if (false === $inserted || !$row) {
            $wpdb->query('ROLLBACK');
            error_log('Website Watchdog could not save a local incident.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }
        $cloud_delivery = 'slack_webhook' === get_option('website_watchdog_notification_method', 'email');
        $notify = 1 === $inserted;
        $sync = $cloud_delivery && $notify;
        if (!$notify) {
            $reopened = 'resolved' === $row['status'] || ('muted' === $row['status'] && !(bool) $row['mute_future']);
            $previous = json_decode($row['payload'], true);
            $severity_changed = ($previous['severity'] ?? null) !== ($payload['severity'] ?? null);
            $row['status'] = $reopened ? 'reopened' : $row['status'];
            $row['revision'] = (int) $row['revision'] + ($reopened || $severity_changed ? 1 : 0);
            $row['last_seen'] = max($row['last_seen'], $date);
            $row['payload'] = $json;
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET occurrences = occurrences + 1, last_seen = %s, payload = %s, status = %s, revision = %d WHERE id = %s",
                $row['last_seen'], $json, $row['status'], $row['revision'], $row['id']
            ));
            if (false === $updated) {
                $wpdb->query('ROLLBACK');
                error_log('Website Watchdog could not update a local incident.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                return false;
            }
            $notify = $reopened;
            $sync = $cloud_delivery && ($reopened || $severity_changed);
        }
        $event_id = null;
        if ($sync) {
            $event_id = $this->enqueue($row, $notify);
            if (null === $event_id) {
                $wpdb->query('ROLLBACK');
                return false;
            }
        }
        if (false === $wpdb->query('COMMIT')) {
            error_log('Website Watchdog could not commit a local incident.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }
        if ($notify && !$cloud_delivery) {
            (new IncidentEmailNotifier())->send($row);
        }
        if ($sync) {
            $this->schedule_flush();
            $this->deliver_now($event_id);
        }
        return true;
    }

    public function recent_changes(int $time): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'watchdog_changes';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, occurred_at, payload, shareable FROM $table WHERE occurred_at >= %s AND occurred_at <= %s ORDER BY occurred_at DESC, id DESC",
            gmdate('Y-m-d H:i:s', $time - 900), gmdate('Y-m-d H:i:s', $time)
        ), ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error) {
            error_log('Website Watchdog could not read local changes.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return null;
        }
        $changes = [];
        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true);
            if (is_array($payload)) {
                $changes[] = ['id' => (int) $row['id'], 'timestamp' => strtotime($row['occurred_at'] . ' UTC'), 'payload' => $payload, 'shareable' => (bool) $row['shareable']];
            }
        }
        return $changes;
    }

    private function enqueue(array $incident, bool $notify): ?string
    {
        global $wpdb;
        $local_payload = json_decode($incident['payload'], true);
        if (!is_array($local_payload) || (isset($local_payload['severity']) && !in_array($local_payload['severity'], self::SEVERITIES, true))) {
            error_log('Website Watchdog could not read local incident severity.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return null;
        }
        $time = strtotime($incident['last_seen'] . ' UTC');
        $changes = [];
        $recent = $this->recent_changes($time);
        if (null === $recent) {
            return null;
        }
        foreach ($recent as $change) {
            $payload = $change['payload'];
            if (!$change['shareable'] || !in_array($payload['action'] ?? '', self::ACTIONS, true) || empty($payload['target'])) {
                continue;
            }
            $item = [
                'action' => $payload['action'],
                'target' => $this->limit_text((string) $payload['target'], 255),
                'timestamp' => gmdate('c', $change['timestamp']),
            ];
            foreach (['old_version', 'new_version'] as $field) {
                if (!empty($payload[$field]) && is_string($payload[$field])) {
                    $item[$field] = $this->limit_text($payload[$field], 100);
                }
            }
            $changes[] = $item;
            if (count($changes) === 20) {
                break;
            }
        }
        $event_id = wp_generate_uuid4();
        $body = [
            'event_id' => $event_id,
            'local_id' => $incident['id'],
            'revision' => (int) $incident['revision'],
            'status' => $incident['status'],
            'mute_future' => (bool) $incident['mute_future'],
            'last_seen_at' => gmdate('c', $time),
            'incident_url' => add_query_arg([
                'page' => 'website-watchdog-settings', 'tab' => 'incidents', 'incident_id' => $incident['id'],
            ], admin_url('admin.php')),
            'recent_changes' => $changes,
            'notify' => $notify,
        ];
        if (isset($local_payload['severity'])) {
            $body['severity'] = $local_payload['severity'];
        }
        $json = wp_json_encode($body);
        if (false === $json || false === $wpdb->insert($wpdb->prefix . 'watchdog_outbox', [
            'event_id' => $event_id, 'payload' => $json, 'created_at' => gmdate('Y-m-d H:i:s'),
        ], ['%s', '%s', '%s'])) {
            error_log('Website Watchdog could not queue a local incident update.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return null;
        }
        return $event_id;
    }

    private function limit_text(string $text, int $length): string
    {
        if (!preg_match_all('/./us', $text, $characters)) {
            return '';
        }
        $result = '';
        $units = 0;
        foreach ($characters[0] as $character) {
            $units += strlen($character) === 4 ? 2 : 1;
            if ($units > $length) {
                break;
            }
            $result .= $character;
        }
        return $result;
    }

    private function schedule_flush(): void
    {
        if (!wp_next_scheduled('website_watchdog_flush_local')) {
            wp_schedule_single_event(time() + 10, 'website_watchdog_flush_local');
        }
    }

    private function deliver_now(string $event_id): void
    {
        global $wpdb;
        static $delivering = false;
        if ($delivering || 'slack_webhook' !== get_option('website_watchdog_notification_method', 'email')
            || '' === (string) get_option('website_watchdog_api_key', '')
            || !get_option('website_watchdog_api_key_verified', false)) {
            return;
        }
        $table = $wpdb->prefix . 'watchdog_outbox';
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, payload FROM $table WHERE event_id = %s", $event_id), ARRAY_A);
        if (!is_array($row)) {
            if ($wpdb->last_error) {
                error_log('Website Watchdog could not read a local incident update.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return;
        }
        $payload = json_decode($row['payload'], true);
        if (!is_array($payload)) {
            error_log('Website Watchdog found an invalid local outbox item.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return;
        }
        $delivering = true;
        try {
            $result = (new Client())->deliver($payload, '/' . WEBSITE_WATCHDOG_API_VERSION . '/incidents/local');
        } finally {
            $delivering = false;
        }
        if (Client::RESULT_OK !== $result) {
            error_log('Website Watchdog could not deliver a local incident update: ' . $result); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        } elseif (false === $wpdb->delete($table, ['id' => $row['id']], ['%d'])) {
            error_log('Website Watchdog could not remove a delivered local update.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    public function get_incident(string $id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'watchdog_incidents';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %s", $id), ARRAY_A);
        if ($wpdb->last_error) {
            error_log('Website Watchdog could not read a local incident.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }
        return is_array($row) ? $row : null;
    }

    public function list_incidents(int $page, int $per_page = 20): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'watchdog_incidents';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY last_seen DESC, id DESC LIMIT %d OFFSET %d",
            $per_page, (max(1, $page) - 1) * $per_page
        ), ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error) {
            error_log('Website Watchdog could not read local incidents.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return null;
        }
        return $rows;
    }

    public function count_incidents(): ?int
    {
        global $wpdb;
        $count = $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'watchdog_incidents');
        if (null === $count || $wpdb->last_error) {
            error_log('Website Watchdog could not count local incidents.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return null;
        }
        return (int) $count;
    }

    public function list_events(int $page, int $per_page = 20): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'watchdog_changes';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, occurred_at, payload, shareable FROM $table ORDER BY occurred_at DESC, id DESC LIMIT %d OFFSET %d",
            $per_page, (max(1, $page) - 1) * $per_page
        ), ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error) {
            error_log('Website Watchdog could not read local events.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return null;
        }
        return $rows;
    }

    public function count_events(): ?int
    {
        global $wpdb;
        $count = $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'watchdog_changes');
        if (null === $count || $wpdb->last_error) {
            error_log('Website Watchdog could not count local events.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return null;
        }
        return (int) $count;
    }

    public function get_event(int $id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'watchdog_changes';
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, occurred_at, payload, shareable FROM $table WHERE id = %d", $id), ARRAY_A);
        if ($wpdb->last_error) {
            error_log('Website Watchdog could not read a local event.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return false;
        }
        return is_array($row) ? $row : null;
    }

    public function update_status(string $id, int $revision, string $status, bool $mute_future): string
    {
        global $wpdb;
        if (!in_array($status, ['new', 'investigating', 'resolved', 'reopened', 'muted'], true)) {
            return 'invalid';
        }
        $table = $wpdb->prefix . 'watchdog_incidents';
        if (false === $wpdb->query('START TRANSACTION')) {
            return 'error';
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %s FOR UPDATE", $id), ARRAY_A);
        if (!$row) {
            $wpdb->query('ROLLBACK');
            return 'not_found';
        }
        if ((int) $row['revision'] !== $revision) {
            $wpdb->query('ROLLBACK');
            return 'conflict';
        }
        $row['status'] = $status;
        $row['mute_future'] = 'muted' === $status && $mute_future ? 1 : 0;
        $row['revision'] = $revision + 1;
        $cloud_delivery = 'slack_webhook' === get_option('website_watchdog_notification_method', 'email');
        $updated = $wpdb->update($table, [
            'status' => $status, 'mute_future' => $row['mute_future'], 'revision' => $row['revision'],
        ], ['id' => $id], ['%s', '%d', '%d'], ['%s']);
        if (false === $updated || ($cloud_delivery && !$this->enqueue($row, false))) {
            $wpdb->query('ROLLBACK');
            error_log('Website Watchdog could not update local incident status.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return 'error';
        }
        if (false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            error_log('Website Watchdog could not commit local incident status.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return 'error';
        }
        if ($cloud_delivery) {
            $this->schedule_flush();
        }
        return 'ok';
    }

    public function flush_outbox(): void
    {
        global $wpdb;
        if (!$this->install()
            || 'slack_webhook' !== get_option('website_watchdog_notification_method', 'email')
            || '' === (string) get_option('website_watchdog_api_key', '')) {
            return;
        }
        $table = $wpdb->prefix . 'watchdog_outbox';
        $rows = $wpdb->get_results("SELECT id, payload FROM $table ORDER BY id ASC LIMIT 10", ARRAY_A);
        if (!is_array($rows) || !$rows) {
            return;
        }
        $client = new Client();
        if (true !== $client->verify()) {
            return;
        }
        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true);
            if (!is_array($payload)) {
                error_log('Website Watchdog found an invalid local outbox item.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                break;
            }
            $result = $client->deliver($payload, '/' . WEBSITE_WATCHDOG_API_VERSION . '/incidents/local');
            if (Client::RESULT_OK !== $result) {
                error_log('Website Watchdog could not deliver a local incident update: ' . $result); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                break;
            }
            if (false === $wpdb->delete($table, ['id' => $row['id']], ['%d'])) {
                error_log('Website Watchdog could not remove a delivered local update.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                break;
            }
        }
        if (count($rows) === 10) {
            $this->schedule_flush();
        }
    }

    public function cleanup(): void
    {
        global $wpdb;
        if (!$this->install()) {
            return;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        foreach (['incidents' => 'last_seen', 'changes' => 'occurred_at'] as $table => $column) {
            $name = $wpdb->prefix . 'watchdog_' . $table;
            if (false === $wpdb->query($wpdb->prepare("DELETE FROM $name WHERE $column < %s", $cutoff))) {
                error_log('Website Watchdog could not clean local records.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
    }
}
