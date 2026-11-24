<?php

namespace WebsiteWatchdog\Agent\Admin;

use WebsiteWatchdog\Agent\Local\Store;

class IncidentsView
{
    public function handle_refresh(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'website-watchdog'));
        }
        check_admin_referer('website_watchdog_refresh_incidents');
        wp_safe_redirect($this->url());
        exit;
    }

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
        wp_safe_redirect(add_query_arg('status_result', $result, $this->url($id)));
        exit;
    }

    public function render(): void
    {
        $store = new Store();
        echo '<div class="wrap"><h1>' . esc_html__('Incident History', 'website-watchdog') . '</h1>';
        if (!$store->install()) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Local incident storage is unavailable.', 'website-watchdog') . '</p></div></div>';
            return;
        }
        // Read-only navigation.
        $id = isset($_GET['incident_id']) ? sanitize_text_field(wp_unslash($_GET['incident_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $result = isset($_GET['status_result']) ? sanitize_key(wp_unslash($_GET['status_result'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ('' !== $result) {
            $message = 'ok' === $result ? __('Status updated.', 'website-watchdog') : __('Status could not be updated; refresh and try again.', 'website-watchdog');
            echo '<div class="notice notice-' . ('ok' === $result ? 'success' : 'error') . '"><p>' . esc_html($message) . '</p></div>';
        }
        if ('' !== $id) {
            $incident = $store->get_incident($id);
            if (!$incident) {
                echo '<p>' . esc_html__('Incident not found or expired.', 'website-watchdog') . '</p>';
            } else {
                $this->render_incident($store, $incident);
            }
        } else {
            $this->render_list($store);
        }
        echo '</div>';
    }

    private function render_list(Store $store): void
    {
        $page = isset($_GET['incident_page']) ? max(1, absint($_GET['incident_page'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $incidents = $store->list_incidents($page);
        if (!$incidents) {
            echo '<p>' . esc_html__('No incident history available yet.', 'website-watchdog') . '</p>';
            return;
        }
        echo '<table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__('Incident', 'website-watchdog') . '</th><th>' . esc_html__('Occurrences', 'website-watchdog') . '</th><th>' . esc_html__('Last seen (UTC)', 'website-watchdog') . '</th><th>' . esc_html__('Status', 'website-watchdog') . '</th></tr></thead><tbody>';
        foreach ($incidents as $incident) {
            $payload = json_decode($incident['payload'], true);
            $message = is_array($payload) ? (string) ($payload['details'] ?? '') : '';
            echo '<tr><td><a href="' . esc_url($this->url($incident['id'])) . '">' . esc_html($message) . '</a></td><td>' . esc_html((string) $incident['occurrences']) . '</td><td>' . esc_html($incident['last_seen']) . '</td><td>' . esc_html($incident['status']) . '</td></tr>';
        }
        echo '</tbody></table>';
        $pages = (int) ceil($store->count_incidents() / 20);
        for ($number = 1; $number <= $pages; $number++) {
            echo '<a class="button" href="' . esc_url(add_query_arg('incident_page', $number, $this->url())) . '">' . esc_html((string) $number) . '</a> ';
        }
    }

    private function render_incident(Store $store, array $incident): void
    {
        $payload = json_decode($incident['payload'], true);
        if (!is_array($payload)) {
            echo '<p>' . esc_html__('Incident data is invalid.', 'website-watchdog') . '</p>';
            return;
        }
        echo '<p><a href="' . esc_url($this->url()) . '">' . esc_html__('Back to incident history', 'website-watchdog') . '</a></p>';
        echo '<h2>' . esc_html((string) ($payload['details'] ?? '')) . '</h2>';
        echo '<p>' . esc_html__('Status:', 'website-watchdog') . ' ' . esc_html($incident['status']) . ' | ' . esc_html__('Occurrences:', 'website-watchdog') . ' ' . esc_html((string) $incident['occurrences']) . '</p>';
        $fields = [
            __('Source', 'website-watchdog') => $payload['source'] ?? '',
            __('Severity', 'website-watchdog') => $payload['severity'] ?? '',
            __('Error type', 'website-watchdog') => $payload['error_type'] ?? '',
            __('URL', 'website-watchdog') => $payload['url'] ?? '',
            __('PHP version', 'website-watchdog') => $payload['php_version'] ?? '',
            __('WordPress version', 'website-watchdog') => $payload['wp_version'] ?? '',
            __('First seen (UTC)', 'website-watchdog') => $incident['first_seen'],
            __('Last seen (UTC)', 'website-watchdog') => $incident['last_seen'],
        ];
        foreach ($fields as $label => $value) {
            if (is_scalar($value) && '' !== (string) $value) {
                echo '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html((string) $value) . '</p>';
            }
        }
        if (!empty($payload['stack_trace']) && is_array($payload['stack_trace'])) {
            echo '<h3>' . esc_html__('Stack trace', 'website-watchdog') . '</h3><pre>' . esc_html(implode("\n", $payload['stack_trace'])) . '</pre>';
        }
        $changes = $store->recent_changes(strtotime($incident['last_seen'] . ' UTC'));
        if (null === $changes) {
            echo '<p>' . esc_html__('Recent changes could not be loaded.', 'website-watchdog') . '</p>';
        } else {
            $source = (string) ($payload['source'] ?? '');
            $preferred = null;
            foreach ($changes as $change) {
                $slugs = $change['payload']['details']['plugin_slug'] ?? [];
                foreach ((array) $slugs as $slug) {
                    if (is_string($slug) && '' !== $slug && false !== strpos($source, $slug)) {
                        $preferred = $change;
                        break 2;
                    }
                }
            }
            $preferred = $preferred ?: ($changes[0] ?? null);
            echo '<h3>' . esc_html__('Recent changes (15 minutes)', 'website-watchdog') . '</h3>';
            if ($preferred) {
                echo '<p>' . esc_html__('Potentially related:', 'website-watchdog') . ' ' . esc_html((string) ($preferred['payload']['action'] ?? '')) . ' ' . esc_html((string) ($preferred['payload']['target'] ?? '')) . '</p>';
            }
            foreach ($changes as $change) {
                echo '<pre>' . esc_html(gmdate('c', $change['timestamp']) . ' ' . wp_json_encode($change['payload'], JSON_PRETTY_PRINT)) . '</pre>';
            }
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="website_watchdog_incident_status">';
        echo '<input type="hidden" name="incident_id" value="' . esc_attr($incident['id']) . '">';
        echo '<input type="hidden" name="revision" value="' . esc_attr((string) $incident['revision']) . '">';
        wp_nonce_field('website_watchdog_incident_status');
        echo '<label>' . esc_html__('Status', 'website-watchdog') . ' <select name="status">';
        foreach (['new', 'investigating', 'resolved', 'reopened', 'muted'] as $status) {
            echo '<option value="' . esc_attr($status) . '"' . selected($incident['status'], $status, false) . '>' . esc_html(ucfirst($status)) . '</option>';
        }
        echo '</select></label> <label><input type="checkbox" name="mute_future" value="1"' . checked((bool) $incident['mute_future'], true, false) . '> ' . esc_html__('Mute future occurrences', 'website-watchdog') . '</label>';
        submit_button(__('Update status', 'website-watchdog'));
        echo '</form>';
    }

    private function url(string $id = ''): string
    {
        $args = ['page' => 'website-watchdog-settings', 'tab' => 'incidents'];
        if ('' !== $id) {
            $args['incident_id'] = $id;
        }
        return add_query_arg($args, admin_url('admin.php'));
    }
}
