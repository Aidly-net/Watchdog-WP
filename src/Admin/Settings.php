<?php

namespace WebsiteWatchdog\Agent\Admin;

use WebsiteWatchdog\Agent\Api;
use WebsiteWatchdog\Agent\Http\Client;

class Settings
{
    protected $incidents_view;

    public function __construct()
    {
        $this->incidents_view = new IncidentsView();
    }

    public static function is_api_key_verified(): bool
    {
        return (bool) get_option('website_watchdog_api_key_verified', false);
    }

    public static function mark_api_key_verified(bool $verified): void
    {
        update_option('website_watchdog_api_key_verified', $verified, false);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'render_verification_notice']);
        add_action('admin_post_website_watchdog_test_event', [$this, 'handle_test_event']);
        add_action('admin_post_website_watchdog_test_incident', [$this, 'handle_test_incident']);
        add_action('admin_post_website_watchdog_refresh_incidents', [$this->incidents_view, 'handle_refresh']);
    }

    public function validate_api_key_option($value, $old_value, $option): string
    {
        $api_key = is_string($value) ? trim(wp_unslash($value)) : '';
        $api_key = sanitize_text_field($api_key);

        if ('' === $api_key) {
            self::mark_api_key_verified(false);

            return '';
        }

        $result = (new Client())->check_key($api_key);

        if (true !== $result['valid']) {
            self::mark_api_key_verified(false);
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Failed to verify API Key with Watchdog server: ' . $result['error']); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            add_settings_error('website_watchdog_api_key', 'invalid_key', __('Could not verify API Key with Watchdog server.', 'website-watchdog'));

            return $old_value;
        }

        $site_id = $result['site_id'];
        remove_filter('pre_update_option_website_watchdog_api_key', [$this, 'validate_api_key_option']);
        update_option('website_watchdog_api_key', $api_key);
        add_filter('pre_update_option_website_watchdog_api_key', [$this, 'validate_api_key_option'], 10, 3);

        update_option('website_watchdog_site_id', $site_id);
        self::mark_api_key_verified(true);
        delete_transient('website_watchdog_cached_incidents');

        return $api_key;
    }

    public function add_menu_page(): void
    {
        add_menu_page(
            __('Website Watchdog', 'website-watchdog'),
            __('Website Watchdog', 'website-watchdog'),
            'manage_options',
            'website-watchdog-settings',
            [$this, 'render_page'],
            'dashicons-shield'
        );
    }

    public function register_settings(): void
    {
        // The nonce is verified immediately below once the option page is identified.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $option_page = isset($_POST['option_page']) ? sanitize_key(wp_unslash($_POST['option_page'])) : '';

        if ('website_watchdog_settings_group' === $option_page) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'website-watchdog'));
            }

            check_admin_referer('website_watchdog_settings_action', 'website_watchdog_settings_nonce');
        }

        add_filter('pre_update_option_website_watchdog_api_key', [$this, 'validate_api_key_option'], 10, 3);

        add_settings_section(
            'website_watchdog_main',
            __('Main Settings', 'website-watchdog'),
            [$this, 'render_section_intro'],
            'website_watchdog_settings_group'
        );

        register_setting(
            'website_watchdog_settings_group',
            'website_watchdog_api_key',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_api_key'],
                'default' => '',
            ]
        );

        add_settings_field(
            'website_watchdog_api_key',
            __('API Key', 'website-watchdog'),
            [$this, 'render_api_key_field'],
            'website_watchdog_settings_group',
            'website_watchdog_main'
        );
    }

    public function sanitize_api_key($value): string
    {
        $value = is_string($value) ? trim(wp_unslash($value)) : '';

        return sanitize_text_field($value);
    }

    public function render_section_intro(): void
    {
        echo '<p>' . esc_html__('Configure the connection to Website Watchdog.', 'website-watchdog') . '</p>';
    }

    public function render_api_key_field(): void
    {
        $value = get_option('website_watchdog_api_key', '');

        echo '<input type="text" id="website_watchdog_api_key" name="website_watchdog_api_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Enter the API key provided by Website Watchdog.', 'website-watchdog')
            . ' <a href="' . esc_url(WEBSITE_WATCHDOG_URL) . '/settings" target="_blank" rel="noopener noreferrer">'
            . esc_html__('(Click here to get one)', 'website-watchdog') . '</a></p>';
    }

    public function render_verification_notice(): void
    {
        if (!current_user_can('manage_options') || self::is_api_key_verified()) {
            return;
        }

        $api_key = get_option('website_watchdog_api_key', '');
        if ('' === $api_key) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__('Website Watchdog:', 'website-watchdog') . '</strong> ' . esc_html__('Your API key is not verified, so event delivery is paused until the key is updated and re-validated.', 'website-watchdog') . '</p></div>';
    }

    public function handle_test_event(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'website-watchdog'));
        }

        check_admin_referer('website_watchdog_test_event_nonce');

        $action = isset($_POST['event_action']) ? sanitize_key(wp_unslash($_POST['event_action'])) : '';
        $target = isset($_POST['event_target']) ? sanitize_text_field(wp_unslash($_POST['event_target'])) : '';
        $message = isset($_POST['event_message']) ? sanitize_textarea_field(wp_unslash($_POST['event_message'])) : '';

        if ('' === $action || '' === $target || '' === $message) {
            $this->redirect_with_test_result('event', false, __('Action, target, and details are required.', 'website-watchdog'));
        }

        $sent = Api::capture_event($action, ['details' => ['message' => $message]], $target);
        $this->redirect_with_test_result('event', $sent, $sent ? __('Test event sent.', 'website-watchdog') : __('Test event could not be sent.', 'website-watchdog'));
    }

    public function handle_test_incident(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'website-watchdog'));
        }

        check_admin_referer('website_watchdog_test_incident_nonce');

        $details = isset($_POST['incident_details']) ? sanitize_textarea_field(wp_unslash($_POST['incident_details'])) : '';
        $severity = isset($_POST['incident_severity']) ? sanitize_key(wp_unslash($_POST['incident_severity'])) : 'error';
        $source = isset($_POST['incident_source']) ? sanitize_text_field(wp_unslash($_POST['incident_source'])) : '';

        if ('' === $details) {
            $this->redirect_with_test_result('incident', false, __('Incident details are required.', 'website-watchdog'));
        }

        $sent = Api::capture_incident($details, $severity, '' !== $source ? $source : null);
        $this->redirect_with_test_result('incident', $sent, $sent ? __('Test incident sent.', 'website-watchdog') : __('Test incident could not be sent.', 'website-watchdog'));
    }

    protected function redirect_with_test_result(string $type, bool $success, string $message): void
    {
        $url = add_query_arg([
            'page' => 'website-watchdog-settings',
            'tab' => 'test',
            'website_watchdog_test' => $type,
            'website_watchdog_test_status' => $success ? 'success' : 'error',
            'website_watchdog_test_message' => $message,
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    public function render_test_result(): void
    {
        // Display-only redirect result; no state is changed, and output is escaped.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['website_watchdog_test_message'], $_GET['website_watchdog_test_status'])) {
            return;
        }

        $status = 'success' === sanitize_key(wp_unslash($_GET['website_watchdog_test_status'])) ? 'success' : 'error';
        $message = sanitize_text_field(wp_unslash($_GET['website_watchdog_test_message']));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        echo '<div class="notice notice-' . esc_attr($status) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    public function render_test_forms(): void
    {
?>
        <hr />
        <h2><?php echo esc_html__('Send Test Data', 'website-watchdog'); ?></h2>
        <p><?php echo esc_html__('Use these forms to verify that custom events and incidents reach Website Watchdog.', 'website-watchdog'); ?></p>
        <h3><?php echo esc_html__('Custom Event', 'website-watchdog'); ?></h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="website_watchdog_test_event" />
            <?php wp_nonce_field('website_watchdog_test_event_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="website_watchdog_event_action"><?php echo esc_html__('Action', 'website-watchdog'); ?></label></th>
                    <td><input type="text" id="website_watchdog_event_action" name="event_action" value="test_event" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="website_watchdog_event_target"><?php echo esc_html__('Target', 'website-watchdog'); ?></label></th>
                    <td><input type="text" id="website_watchdog_event_target" name="event_target" value="Website Watchdog" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="website_watchdog_event_message"><?php echo esc_html__('Details', 'website-watchdog'); ?></label></th>
                    <td><textarea id="website_watchdog_event_message" name="event_message" rows="3" class="large-text" required></textarea></td>
                </tr>
            </table>
            <?php submit_button(__('Send Test Event', 'website-watchdog'), 'secondary', 'submit', false); ?>
        </form>
        <h3><?php echo esc_html__('Custom Incident', 'website-watchdog'); ?></h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="website_watchdog_test_incident" />
            <?php wp_nonce_field('website_watchdog_test_incident_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="website_watchdog_incident_details"><?php echo esc_html__('Details', 'website-watchdog'); ?></label></th>
                    <td><textarea id="website_watchdog_incident_details" name="incident_details" rows="3" class="large-text" required></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="website_watchdog_incident_severity"><?php echo esc_html__('Severity', 'website-watchdog'); ?></label></th>
                    <td>
                        <select id="website_watchdog_incident_severity" name="incident_severity">
                            <?php foreach (['info', 'warning', 'error', 'critical'] as $severity) : ?>
                                <option value="<?php echo esc_attr($severity); ?>" <?php selected('error', $severity); ?>><?php echo esc_html(ucfirst($severity)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="website_watchdog_incident_source"><?php echo esc_html__('Source', 'website-watchdog'); ?></label></th>
                    <td><input type="text" id="website_watchdog_incident_source" name="incident_source" value="website-watchdog-settings" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button(__('Send Test Incident', 'website-watchdog'), 'secondary', 'submit', false); ?>
        </form>
    <?php
    }

    protected function get_active_tab(): string
    {
        // Tab selection is read-only navigation.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return in_array($tab, ['settings', 'test', 'incidents'], true) ? $tab : 'settings';
    }

    protected function render_tabs(string $active_tab): void
    {
        $tabs = [
            'settings' => __('Settings', 'website-watchdog'),
            'test' => __('Send Test Data', 'website-watchdog'),
            'incidents' => __('Incident History', 'website-watchdog'),
        ];

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $tab => $label) {
            $url = add_query_arg([
                'page' => 'website-watchdog-settings',
                'tab' => $tab,
            ], admin_url('admin.php'));
            $class = $active_tab === $tab ? ' nav-tab-active' : '';

            echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'website-watchdog'));
        }

    ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Website Watchdog Settings', 'website-watchdog'); ?></h1>
            <?php $active_tab = $this->get_active_tab(); ?>
            <?php $this->render_tabs($active_tab); ?>
            <?php if (!self::is_api_key_verified() && '' !== get_option('website_watchdog_api_key', '')) : ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html__('Warning: this API key is not verified. Events are paused until the key is corrected and re-verified.', 'website-watchdog'); ?></p>
                </div>
            <?php endif; ?>
            <?php if ('settings' === $active_tab) : ?>
                <?php settings_errors('website_watchdog_api_key'); ?>
                <form method="post" action="options.php">
                    <?php settings_fields('website_watchdog_settings_group'); ?>
                    <?php wp_nonce_field('website_watchdog_settings_action', 'website_watchdog_settings_nonce'); ?>
                    <?php do_settings_sections('website_watchdog_settings_group'); ?>
                    <?php submit_button(__('Save Settings', 'website-watchdog')); ?>
                </form>
            <?php elseif ('test' === $active_tab) : ?>
                <?php $this->render_test_result(); ?>
                <?php $this->render_test_forms(); ?>
            <?php else : ?>
                <?php $this->incidents_view->render(); ?>
            <?php endif; ?>
        </div>
<?php
    }
}
