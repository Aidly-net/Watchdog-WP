<?php

namespace WebsiteWatchdog\Agent\Admin;

use WebsiteWatchdog\Agent\Local\Store;

class IncidentsView
{
    public function handle_status(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'website-watchdog'));
        }
        check_admin_referer('website_watchdog_incident_status');
        $id = isset($_POST['incident_id']) ? sanitize_text_field(wp_unslash($_POST['incident_id'])) : '';
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
        $revision = isset($_POST['revision']) ? absint($_POST['revision']) : 0;
        $store = new Store();
        $result = $store->install()
            ? $store->update_status($id, $revision, $status, isset($_POST['mute_future']))
            : 'error';
        wp_safe_redirect(add_query_arg('status_result', $result, $this->incident_url($id)));
        exit;
    }

    public function render(): void
    {
        $store = new Store();
        echo '<section class="watchdog-history"><h2>' . esc_html__('Incident History', 'website-watchdog') . '</h2>';
        if (!$store->install()) {
            $this->error(__('Local incident storage is unavailable.', 'website-watchdog'));
            echo '</section>';
            return;
        }
        $id = isset($_GET['incident_id']) ? sanitize_text_field(wp_unslash($_GET['incident_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $result = isset($_GET['status_result']) ? sanitize_key(wp_unslash($_GET['status_result'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ('' !== $result) {
            $message = 'ok' === $result ? __('Status updated.', 'website-watchdog') : __('Status could not be updated; refresh and try again.', 'website-watchdog');
            echo '<div class="notice notice-' . ('ok' === $result ? 'success' : 'error') . ' inline"><p>' . esc_html($message) . '</p></div>';
        }
        if ('' === $id) {
            $this->render_incident_list($store);
        } else {
            $incident = $store->get_incident($id);
            if (false === $incident) {
                $this->error(__('Incident could not be loaded.', 'website-watchdog'));
            } elseif ($incident) {
                $this->render_incident($store, $incident);
            } else {
                $this->error(__('Incident not found or expired.', 'website-watchdog'));
            }
        }
        echo '</section>';
    }

    public function render_events(): void
    {
        $store = new Store();
        echo '<section class="watchdog-history"><h2>' . esc_html__('Event History', 'website-watchdog') . '</h2>';
        if (!$store->install()) {
            $this->error(__('Local event storage is unavailable.', 'website-watchdog'));
        } elseif (isset($_GET['event_id'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $id = absint($_GET['event_id']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $event = $store->get_event($id);
            if (false === $event) {
                $this->error(__('Event could not be loaded.', 'website-watchdog'));
            } elseif (!$event) {
                $this->error(__('Event not found or expired.', 'website-watchdog'));
            } else {
                $this->render_event($event);
            }
        } else {
            $this->render_event_list($store);
        }
        echo '</section>';
    }

    private function render_incident_list(Store $store): void
    {
        $page = isset($_GET['incident_page']) ? max(1, absint($_GET['incident_page'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $incidents = $store->list_incidents($page);
        $count = $store->count_incidents();
        echo '<p class="watchdog-intro">' . esc_html__('Incidents are grouped and stored on this site for 30 days after they were last seen.', 'website-watchdog') . '</p>';
        if (null === $incidents || null === $count) {
            $this->error(__('Incidents could not be loaded.', 'website-watchdog'));
            return;
        }
        if (!$incidents && 1 === $page) {
            echo '<div class="watchdog-empty">' . esc_html__('No incident history available yet.', 'website-watchdog') . '</div>';
            return;
        }
        echo '<div class="watchdog-table-wrap"><table class="widefat striped watchdog-table"><thead><tr><th scope="col">' . esc_html__('Incident', 'website-watchdog') . '</th><th scope="col">' . esc_html__('Occurrences', 'website-watchdog') . '</th><th scope="col">' . esc_html__('Last seen (UTC)', 'website-watchdog') . '</th><th scope="col">' . esc_html__('Status', 'website-watchdog') . '</th></tr></thead><tbody>';
        foreach ($incidents as $incident) {
            $payload = json_decode($incident['payload'], true);
            $message = is_array($payload) && !empty($payload['details']) ? (string) $payload['details'] : __('Incident details unavailable', 'website-watchdog');
            echo '<tr><td class="watchdog-primary"><a href="' . esc_url($this->incident_url($incident['id'])) . '">' . esc_html($message) . '</a>';
            if (is_array($payload) && !empty($payload['source'])) {
                echo '<span class="watchdog-secondary">' . esc_html((string) $payload['source']) . '</span>';
            }
            echo '</td><td>' . esc_html((string) $incident['occurrences']) . '</td><td>' . esc_html($incident['last_seen']) . '</td><td><span class="watchdog-badge watchdog-status-' . esc_attr(sanitize_key($incident['status'])) . '">' . esc_html($this->status_label($incident['status'])) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';
        $this->pagination('incidents', 'incident_page', $page, $count);
    }

    private function render_event_list(Store $store): void
    {
        $page = isset($_GET['event_page']) ? max(1, absint($_GET['event_page'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $events = $store->list_events($page);
        $count = $store->count_events();
        echo '<p class="watchdog-intro">' . esc_html__('WordPress changes and custom events are stored on this site for 30 days.', 'website-watchdog') . '</p>';
        if (null === $events || null === $count) {
            $this->error(__('Events could not be loaded.', 'website-watchdog'));
            return;
        }
        if (!$events && 1 === $page) {
            echo '<div class="watchdog-empty">' . esc_html__('No event history available yet.', 'website-watchdog') . '</div>';
            return;
        }
        echo '<div class="watchdog-table-wrap"><table class="widefat striped watchdog-table"><thead><tr><th scope="col">' . esc_html__('Event', 'website-watchdog') . '</th><th scope="col">' . esc_html__('Target', 'website-watchdog') . '</th><th scope="col">' . esc_html__('Type', 'website-watchdog') . '</th><th scope="col">' . esc_html__('Occurred (UTC)', 'website-watchdog') . '</th></tr></thead><tbody>';
        foreach ($events as $event) {
            $payload = json_decode($event['payload'], true);
            $action = is_array($payload) && !empty($payload['action']) ? (string) $payload['action'] : __('Event details unavailable', 'website-watchdog');
            $target = is_array($payload) && isset($payload['target']) && is_scalar($payload['target']) ? (string) $payload['target'] : '';
            echo '<tr><td class="watchdog-primary"><a href="' . esc_url($this->event_url((int) $event['id'])) . '">' . esc_html($action) . '</a></td><td>' . esc_html($target) . '</td><td>' . esc_html($this->event_type($event)) . '</td><td>' . esc_html($event['occurred_at']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        $this->pagination('events', 'event_page', $page, $count);
    }

    private function render_event(array $event): void
    {
        $payload = json_decode($event['payload'], true);
        echo '<p><a class="watchdog-back" href="' . esc_url($this->event_url()) . '">&larr; ' . esc_html__('Back to event history', 'website-watchdog') . '</a></p>';
        if (!is_array($payload)) {
            $this->error(__('Event data is invalid.', 'website-watchdog'));
            return;
        }
        echo '<div class="watchdog-card"><h3>' . esc_html((string) ($payload['action'] ?? __('Event', 'website-watchdog'))) . '</h3>';
        $this->fields([
            __('Target', 'website-watchdog') => $payload['target'] ?? '',
            __('Type', 'website-watchdog') => $this->event_type($event),
            __('Occurred (UTC)', 'website-watchdog') => $event['occurred_at'],
            __('Old version', 'website-watchdog') => $payload['old_version'] ?? '',
            __('New version', 'website-watchdog') => $payload['new_version'] ?? '',
        ]);
        if (isset($payload['details'])) {
            echo '<h4>' . esc_html__('Details', 'website-watchdog') . '</h4>';
            $this->render_data($payload['details']);
        }
        echo '</div>';
    }

    private function render_incident(Store $store, array $incident): void
    {
        $payload = json_decode($incident['payload'], true);
        echo '<p><a class="watchdog-back" href="' . esc_url($this->incident_url()) . '">&larr; ' . esc_html__('Back to incident history', 'website-watchdog') . '</a></p>';
        if (!is_array($payload)) {
            $this->error(__('Incident data is invalid.', 'website-watchdog'));
            return;
        }
        echo '<div class="watchdog-detail-grid"><div><div class="watchdog-card"><span class="watchdog-badge watchdog-status-' . esc_attr(sanitize_key($incident['status'])) . '">' . esc_html($this->status_label($incident['status'])) . '</span>';
        echo '<h3 class="watchdog-incident-title">' . esc_html((string) ($payload['details'] ?? '')) . '</h3>';
        $this->fields([
            __('Source', 'website-watchdog') => $payload['source'] ?? '',
            __('Severity', 'website-watchdog') => $payload['severity'] ?? '',
            __('Error type', 'website-watchdog') => $payload['error_type'] ?? '',
            __('URL', 'website-watchdog') => $payload['url'] ?? '',
            __('PHP version', 'website-watchdog') => $payload['php_version'] ?? '',
            __('WordPress version', 'website-watchdog') => $payload['wp_version'] ?? '',
            __('Occurrences', 'website-watchdog') => $incident['occurrences'],
            __('First seen (UTC)', 'website-watchdog') => $incident['first_seen'],
            __('Last seen (UTC)', 'website-watchdog') => $incident['last_seen'],
        ]);
        if (!empty($payload['stack_trace']) && is_array($payload['stack_trace'])) {
            echo '<h4>' . esc_html__('Stack trace', 'website-watchdog') . '</h4><pre class="watchdog-code">' . esc_html(implode("\n", $payload['stack_trace'])) . '</pre>';
        }
        echo '</div>';
        $changes = $store->recent_changes(strtotime($incident['last_seen'] . ' UTC'));
        echo '<div class="watchdog-card"><h3>' . esc_html__('Recent changes (15 minutes)', 'website-watchdog') . '</h3>';
        if (null === $changes) {
            $this->error(__('Recent changes could not be loaded.', 'website-watchdog'));
        } elseif (!$changes) {
            echo '<p>' . esc_html__('No recent changes found.', 'website-watchdog') . '</p>';
        } else {
            $source = (string) ($payload['source'] ?? '');
            $preferred = null;
            foreach ($changes as $change) {
                foreach ((array) ($change['payload']['details']['plugin_slug'] ?? []) as $slug) {
                    if (is_string($slug) && '' !== $slug && false !== strpos($source, $slug)) {
                        $preferred = $change;
                        break 2;
                    }
                }
            }
            $preferred = $preferred ?: $changes[0];
            echo '<p>' . esc_html__('Potentially related:', 'website-watchdog') . ' <a href="' . esc_url($this->event_url($preferred['id'])) . '">' . esc_html((string) ($preferred['payload']['action'] ?? '')) . ' ' . esc_html((string) ($preferred['payload']['target'] ?? '')) . '</a></p><ul class="watchdog-change-list">';
            foreach ($changes as $change) {
                echo '<li><a href="' . esc_url($this->event_url($change['id'])) . '">' . esc_html((string) ($change['payload']['action'] ?? '')) . ' ' . esc_html((string) ($change['payload']['target'] ?? '')) . '</a><span>' . esc_html(gmdate('Y-m-d H:i:s', $change['timestamp'])) . ' UTC</span></li>';
            }
            echo '</ul>';
        }
        echo '</div></div><aside class="watchdog-card watchdog-status-card"><h3>' . esc_html__('Manage incident', 'website-watchdog') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="website_watchdog_incident_status"><input type="hidden" name="incident_id" value="' . esc_attr($incident['id']) . '"><input type="hidden" name="revision" value="' . esc_attr((string) $incident['revision']) . '">';
        wp_nonce_field('website_watchdog_incident_status');
        echo '<label for="watchdog-status">' . esc_html__('Status', 'website-watchdog') . '</label><select id="watchdog-status" name="status">';
        foreach (['new', 'investigating', 'resolved', 'reopened', 'muted'] as $status) {
            echo '<option value="' . esc_attr($status) . '"' . selected($incident['status'], $status, false) . '>' . esc_html($this->status_label($status)) . '</option>';
        }
        echo '</select><label class="watchdog-mute"><input type="checkbox" name="mute_future" value="1"' . checked((bool) $incident['mute_future'], true, false) . '> ' . esc_html__('Mute future occurrences', 'website-watchdog') . '</label>';
        submit_button(__('Update status', 'website-watchdog'));
        echo '</form></aside></div>';
    }

    private function fields(array $fields): void
    {
        echo '<dl class="watchdog-fields">';
        foreach ($fields as $label => $value) {
            if (is_scalar($value) && '' !== (string) $value) {
                echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html((string) $value) . '</dd></div>';
            }
        }
        echo '</dl>';
    }

    private function render_data($data): void
    {
        $value = is_scalar($data) ? (string) $data : wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $value) {
            $this->error(__('Event details could not be displayed.', 'website-watchdog'));
            return;
        }
        echo '<pre class="watchdog-code">' . esc_html((string) $value) . '</pre>';
    }

    private function event_type(array $event): string
    {
        return (bool) $event['shareable'] ? __('WordPress change', 'website-watchdog') : __('Custom event', 'website-watchdog');
    }

    private function status_label(string $status): string
    {
        $labels = [
            'new' => __('New', 'website-watchdog'),
            'investigating' => __('Investigating', 'website-watchdog'),
            'resolved' => __('Resolved', 'website-watchdog'),
            'reopened' => __('Reopened', 'website-watchdog'),
            'muted' => __('Muted', 'website-watchdog'),
        ];
        return $labels[$status] ?? $status;
    }

    private function pagination(string $tab, string $param, int $page, int $count): void
    {
        $pages = max(1, (int) ceil($count / 20));
        if ($pages <= 1 && $page <= 1) {
            return;
        }
        $url = 'events' === $tab ? $this->event_url() : $this->incident_url();
        echo '<nav class="watchdog-pagination" aria-label="' . esc_attr__('History pagination', 'website-watchdog') . '">';
        if ($page > 1) {
            echo '<a class="button" href="' . esc_url(add_query_arg($param, $page - 1, $url)) . '">' . esc_html__('Previous', 'website-watchdog') . '</a>';
        }
        echo '<span>' . esc_html(sprintf(__('Page %1$d of %2$d', 'website-watchdog'), $page, $pages)) . '</span>';
        if ($page < $pages) {
            echo '<a class="button" href="' . esc_url(add_query_arg($param, $page + 1, $url)) . '">' . esc_html__('Next', 'website-watchdog') . '</a>';
        }
        echo '</nav>';
    }

    private function error(string $message): void
    {
        echo '<div class="notice notice-error inline"><p>' . esc_html($message) . '</p></div>';
    }

    private function incident_url(string $id = ''): string
    {
        $args = ['page' => 'website-watchdog-settings', 'tab' => 'incidents'];
        if ('' !== $id) {
            $args['incident_id'] = $id;
        }
        return add_query_arg($args, admin_url('admin.php'));
    }

    private function event_url(int $id = 0): string
    {
        $args = ['page' => 'website-watchdog-settings', 'tab' => 'events'];
        if ($id > 0) {
            $args['event_id'] = $id;
        }
        return add_query_arg($args, admin_url('admin.php'));
    }
}
