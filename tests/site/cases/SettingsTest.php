<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Admin\Settings;
use WebsiteWatchdog\Agent\SelfTest\Sandbox;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class SettingsTest extends TestCase
{
    private const GROUP = 'website_watchdog_settings_group';
    private const OPTION = 'website_watchdog_api_key';

    private $settings;
    private $settings_registered = false;

    public function setUp(): void
    {
        $this->settings = new Settings();
    }

    public function tearDown(): void
    {
        if ($this->settings_registered) {
            unregister_setting(self::GROUP, self::OPTION);
        }
        remove_action('toplevel_page_website-watchdog-settings', [$this->settings, 'render_page']);
        remove_filter(Sandbox::PRE_UPDATE_HOOK, [$this->settings, 'validate_api_key_option'], 10);
    }

    private function register_settings(): void
    {
        $this->set_request();
        $this->settings->register_settings();
        $this->settings_registered = true;
    }

    private function output(string $method): string
    {
        return $this->capture([$this->settings, $method]);
    }

    private function key_is_verified(): bool
    {
        return (bool) get_option('website_watchdog_api_key_verified', false);
    }

    // ---- verification flag --------------------------------------------------------------------------------------

    public function test_verified_flag_round_trips(): void
    {
        Settings::mark_api_key_verified(true);
        $this->assert_true(Settings::is_api_key_verified());

        Settings::mark_api_key_verified(false);
        $this->assert_false(Settings::is_api_key_verified());
    }

    // ---- API key validation -------------------------------------------------------------------------------------

    public function test_empty_key_clears_verification(): void
    {
        $this->configure_verified_key();

        $this->assert_same('', $this->settings->validate_api_key_option('   ', 'old', self::OPTION));
        $this->assert_false($this->key_is_verified());
        $this->assert_count(0, $this->http_calls());
    }

    public function test_non_string_key_is_rejected_as_empty(): void
    {
        $this->assert_same('', $this->settings->validate_api_key_option(['x'], 'old', self::OPTION));
    }

    public function test_rejected_key_keeps_old_value_and_reports_error(): void
    {
        $this->configure_verified_key();
        $this->mock_http_response(401, '');

        $result = $this->settings->validate_api_key_option('bad-key', 'old-key', self::OPTION);

        $this->assert_same('old-key', $result);
        $this->assert_false($this->key_is_verified());
        $codes = wp_list_pluck(get_settings_errors(self::OPTION), 'code');
        $this->assert_contains('invalid_key', $codes);
    }

    public function test_unreachable_server_keeps_old_value(): void
    {
        $this->mock_http_error();

        $this->assert_same('old-key', $this->settings->validate_api_key_option('new-key', 'old-key', self::OPTION));
        $this->assert_false($this->key_is_verified());
    }

    public function test_valid_key_is_saved_and_site_registered(): void
    {
        $this->mock_http_response(200, '{"data":{"site_id":"site-42"}}');
        set_transient('website_watchdog_cached_incidents', ['stale'], 60);

        $result = $this->settings->validate_api_key_option('  good-key ', '', self::OPTION);

        $this->assert_same('good-key', $result);
        $this->assert_same('good-key', get_option(self::OPTION));
        $this->assert_same('site-42', get_option('website_watchdog_site_id'));
        $this->assert_true($this->key_is_verified());
        $this->assert_false(get_transient('website_watchdog_cached_incidents'));
        $this->assert_same('Bearer good-key', $this->last_http_call()['args']['headers']['Authorization']);
    }

    public function test_validation_filter_is_reattached_after_save(): void
    {
        $this->mock_http_response(200, '{"data":{"site_id":"s"}}');

        $this->settings->validate_api_key_option('k', '', self::OPTION);

        $this->assert_not_empty(has_filter(Sandbox::PRE_UPDATE_HOOK, [$this->settings, 'validate_api_key_option']));
    }

    public function test_sanitize_api_key(): void
    {
        $this->assert_same('abc', $this->settings->sanitize_api_key("  <b>abc</b>\n"));
        $this->assert_same('', $this->settings->sanitize_api_key(['x']));
        $this->assert_same('', $this->settings->sanitize_api_key(null));
    }

    // ---- registration -------------------------------------------------------------------------------------------

    public function test_add_menu_page_registers_top_level_page_for_admins(): void
    {
        global $menu;
        $this->as_admin();

        $this->settings->add_menu_page();

        $found = null;
        foreach ((array) $menu as $item) {
            if (isset($item[2]) && 'website-watchdog-settings' === $item[2]) {
                $found = $item;
            }
        }
        $this->assert_not_empty($found, 'Menu entry was not added');
        $this->assert_same('manage_options', $found[1]);
        $this->assert_same('Website Watchdog', $found[0]);
    }

    public function test_register_settings_registers_setting_section_and_field(): void
    {
        global $wp_settings_sections, $wp_settings_fields;

        $this->register_settings();

        $registered = get_registered_settings();
        $this->assert_array_has_key(self::OPTION, $registered);
        $this->assert_same('string', $registered[self::OPTION]['type']);
        $this->assert_array_has_key('website_watchdog_main', $wp_settings_sections[self::GROUP]);
        $this->assert_array_has_key(self::OPTION, $wp_settings_fields[self::GROUP]['website_watchdog_main']);
        $this->assert_not_empty(has_filter(Sandbox::PRE_UPDATE_HOOK, [$this->settings, 'validate_api_key_option']));
    }

    public function test_settings_form_post_requires_manage_options(): void
    {
        $this->as_visitor();
        $this->set_request([], ['option_page' => self::GROUP]);

        $message = $this->expect_die([$this->settings, 'register_settings']);

        $this->assert_contains('permissions', $message);
    }

    public function test_settings_form_post_requires_valid_nonce(): void
    {
        $this->as_admin();
        $this->set_request([], ['option_page' => self::GROUP]);

        $this->expect_die([$this->settings, 'register_settings']);
    }

    public function test_settings_form_post_with_valid_nonce_registers(): void
    {
        $this->as_admin();
        $this->set_request([], [
            'option_page' => self::GROUP,
            'website_watchdog_settings_nonce' => wp_create_nonce('website_watchdog_settings_action'),
        ]);

        $this->settings->register_settings();
        $this->settings_registered = true;

        $this->assert_array_has_key(self::OPTION, get_registered_settings());
    }

    // ---- rendering ----------------------------------------------------------------------------------------------

    public function test_section_intro_renders_paragraph(): void
    {
        $out = $this->output('render_section_intro');

        $this->assert_contains('<p>', $out);
        $this->assert_contains('Configure the connection', $out);
    }

    public function test_api_key_field_escapes_stored_value(): void
    {
        $this->set_option(self::OPTION, '"><script>alert(1)</script>');

        $out = $this->output('render_api_key_field');

        $this->assert_contains('name="website_watchdog_api_key"', $out);
        $this->assert_not_contains('<script>alert(1)', $out);
        $this->assert_contains('rel="noopener noreferrer"', $out);
        $this->assert_contains(esc_url(WEBSITE_WATCHDOG_URL), $out);
    }

    public function test_verification_notice_is_shown_for_unverified_key(): void
    {
        $this->as_admin();
        $this->set_option(self::OPTION, 'k');
        $this->set_option('website_watchdog_api_key_verified', false);

        $out = $this->output('render_verification_notice');

        $this->assert_contains('notice-warning', $out);
        $this->assert_contains('not verified', $out);
    }

    public function test_verification_notice_is_hidden_when_not_needed(): void
    {
        $this->as_admin();

        $this->set_option(self::OPTION, 'k');
        $this->set_option('website_watchdog_api_key_verified', true);
        $this->assert_same('', $this->output('render_verification_notice'), 'verified key');

        $this->set_option(self::OPTION, '');
        $this->set_option('website_watchdog_api_key_verified', false);
        $this->assert_same('', $this->output('render_verification_notice'), 'no key');

        $this->set_option(self::OPTION, 'k');
        $this->as_visitor();
        $this->assert_same('', $this->output('render_verification_notice'), 'non-admin');
    }

    public function test_test_result_notice_renders_escaped(): void
    {
        $this->set_request(['website_watchdog_test_status' => 'success', 'website_watchdog_test_message' => 'Sent <b>ok</b>']);

        $out = $this->output('render_test_result');

        $this->assert_contains('notice-success', $out);
        $this->assert_contains('Sent ok', $out);
        $this->assert_not_contains('<b>', $out);
    }

    public function test_test_result_unknown_status_is_shown_as_error(): void
    {
        $this->set_request(['website_watchdog_test_status' => 'weird', 'website_watchdog_test_message' => 'x']);

        $this->assert_contains('notice-error', $this->output('render_test_result'));
    }

    public function test_test_result_is_silent_without_parameters(): void
    {
        $this->set_request();

        $this->assert_same('', $this->output('render_test_result'));
    }

    public function test_test_forms_render_both_forms_with_nonces(): void
    {
        $out = $this->output('render_test_forms');

        $this->assert_contains('value="website_watchdog_test_event"', $out);
        $this->assert_contains('value="website_watchdog_test_incident"', $out);
        $this->assert_contains('value="website_watchdog_test_error_handler"', $out);
        $this->assert_contains('value="website_watchdog_test_exception_handler"', $out);
        $this->assert_contains('value="website_watchdog_test_shutdown_handler"', $out);
        $this->assert_contains('admin-post.php', $out);
        $this->assert_same(5, substr_count($out, 'name="_wpnonce"'), 'one nonce per form');
        foreach (['info', 'warning', 'error', 'critical'] as $severity) {
            $this->assert_contains('value="' . $severity . '"', $out);
        }
        $this->assert_matches('/<option value="error"[^>]*selected/', $out, 'error should be the default severity');
    }

    public function test_test_handlers_require_manage_options_and_nonce(): void
    {
        $handlers = [
            ['handle_test_error_handler', 'website_watchdog_test_error_handler_nonce'],
            ['handle_test_exception_handler', 'website_watchdog_test_exception_handler_nonce'],
            ['handle_test_shutdown_handler', 'website_watchdog_test_shutdown_handler_nonce'],
        ];

        foreach ($handlers as [$method, $nonce_action]) {
            $this->as_visitor();
            $this->post_with_nonce($nonce_action);
            $this->expect_die([$this->settings, $method]);

            $this->as_admin();
            $this->set_request([], []);
            $this->expect_die([$this->settings, $method]);
        }
    }

    public function test_test_error_handler_dispatches_simulated_php_error(): void
    {
        $this->as_admin();
        $this->configure_verified_key();
        $this->post_with_nonce('website_watchdog_test_error_handler_nonce');

        $redirect = $this->expect_redirect([$this->settings, 'handle_test_error_handler']);

        $this->assert_same('success', $redirect['query']['website_watchdog_test_status']);
        $payload = $this->last_http_json()['payload'];
        $this->assert_same('php_error', $payload['error_type']);
        $this->assert_contains('simulated PHP error handler event', $payload['details']);
    }

    public function test_test_exception_handler_dispatches_simulated_exception(): void
    {
        $this->as_admin();
        $this->configureVerifiedKey();
        $this->post_with_nonce('website_watchdog_test_exception_handler_nonce');

        $redirect = $this->expect_redirect([$this->settings, 'handle_test_exception_handler']);

        $this->assert_same('success', $redirect['query']['website_watchdog_test_status']);
        $payload = $this->last_http_json()['payload'];
        $this->assert_same('RuntimeException', $payload['error_type']);
        $this->assert_contains('simulated exception handler event', $payload['details']);
    }

    public function test_test_shutdown_handler_dispatches_simulated_fatal_error(): void
    {
        $this->as_admin();
        $this->configure_verified_key();
        $this->post_with_nonce('website_watchdog_test_shutdown_handler_nonce');

        $redirect = $this->expect_redirect([$this->settings, 'handle_test_shutdown_handler']);

        $this->assert_same('success', $redirect['query']['website_watchdog_test_status']);
        $payload = $this->last_http_json()['payload'];
        $this->assert_same('php_fatal', $payload['error_type']);
        $this->assert_contains('simulated shutdown handler event', $payload['details']);
    }

    public function test_settings_page_requires_manage_options(): void
    {
        $this->as_visitor();

        $this->expect_die([$this->settings, 'render_page']);
    }

    public function test_settings_page_defaults_to_settings_tab(): void
    {
        $this->as_admin();
        $this->register_settings();

        $out = $this->output('render_page');

        $this->assert_contains('Website Watchdog Settings', $out);
        $this->assert_contains('nav-tab-wrapper', $out);
        $this->assert_same(1, substr_count($out, 'nav-tab-active'));
        foreach (['Settings', 'Send Test Data', 'Incident History'] as $label) {
            $this->assert_contains('>' . $label . '</a>', $out);
        }
        $this->assert_contains('action="options.php"', $out);
        $this->assert_contains("value='" . self::GROUP . "'", $out);
        $this->assert_contains('website_watchdog_settings_nonce', $out);
        $this->assert_contains('name="website_watchdog_api_key"', $out);
    }

    public function test_settings_page_unknown_tab_falls_back_to_settings(): void
    {
        $this->as_admin();
        $this->register_settings();
        $this->set_request(['tab' => 'nonsense']);

        $this->assert_contains('name="website_watchdog_api_key"', $this->output('render_page'));
    }

    public function test_settings_page_test_tab(): void
    {
        $this->as_admin();
        $this->set_request(['tab' => 'test', 'website_watchdog_test_status' => 'success', 'website_watchdog_test_message' => 'Done']);

        $out = $this->output('render_page');

        $this->assert_contains('Custom Event', $out);
        $this->assert_contains('Custom Incident', $out);
        $this->assert_contains('Done', $out);
        $this->assert_not_contains('name="website_watchdog_api_key"', $out);
    }

    public function test_settings_page_incidents_tab(): void
    {
        $this->as_admin();
        $this->configure_verified_key();
        $this->mock_http_response(200, '{"data":{"incidents":[]}}');
        $this->set_request(['tab' => 'incidents']);

        $out = $this->output('render_page');

        $this->assert_contains('No incident history available yet.', $out);
    }

    public function test_settings_page_warns_about_unverified_key(): void
    {
        $this->as_admin();
        $this->set_option(self::OPTION, 'k');
        $this->set_option('website_watchdog_api_key_verified', false);
        $this->register_settings();

        $this->assert_contains('this API key is not verified', $this->output('render_page'));
    }

    // ---- test event / incident handlers -------------------------------------------------------------------------

    public function test_test_event_requires_manage_options(): void
    {
        $this->as_visitor();
        $this->post_with_nonce('website_watchdog_test_event_nonce', ['event_action' => 'a', 'event_target' => 't', 'event_message' => 'm']);

        $this->expect_die([$this->settings, 'handle_test_event']);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_test_event_requires_valid_nonce(): void
    {
        $this->as_admin();
        $this->set_request([], ['event_action' => 'a', 'event_target' => 't', 'event_message' => 'm']);

        $this->expect_die([$this->settings, 'handle_test_event']);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_test_event_rejects_nonce_for_another_action(): void
    {
        $this->as_admin();
        $this->post_with_nonce('website_watchdog_test_incident_nonce', ['event_action' => 'a', 'event_target' => 't', 'event_message' => 'm']);

        $this->expect_die([$this->settings, 'handle_test_event']);
    }

    public function test_test_event_requires_all_fields(): void
    {
        $this->as_admin();
        $this->configure_verified_key();
        $this->post_with_nonce('website_watchdog_test_event_nonce', ['event_action' => 'deploy', 'event_target' => '', 'event_message' => 'm']);

        $redirect = $this->expect_redirect([$this->settings, 'handle_test_event']);

        $this->assert_same('error', $redirect['query']['website_watchdog_test_status']);
        $this->assert_same('event', $redirect['query']['website_watchdog_test']);
        $this->assert_same('test', $redirect['query']['tab']);
        $this->assert_same('website-watchdog-settings', $redirect['query']['page']);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_test_event_is_sent_sanitized_and_reports_success(): void
    {
        $this->as_admin();
        $this->configure_verified_key();
        $this->post_with_nonce('website_watchdog_test_event_nonce', [
            'event_action' => 'Deploy Done!',
            'event_target' => '<i>Production</i>',
            'event_message' => 'Release <b>2.0</b>',
        ]);

        $redirect = $this->expect_redirect([$this->settings, 'handle_test_event']);

        $this->assert_same('success', $redirect['query']['website_watchdog_test_status']);
        $payload = $this->last_http_json()['payload'];
        $this->assert_same('deploydone', $payload['action']);
        $this->assert_same('Production', $payload['target']);
        $this->assert_same('Release 2.0', $payload['details']['message']);
    }

    public function test_test_event_reports_failure_when_not_sent(): void
    {
        $this->as_admin();
        delete_option(self::OPTION);
        $this->post_with_nonce('website_watchdog_test_event_nonce', ['event_action' => 'a', 'event_target' => 't', 'event_message' => 'm']);

        $redirect = $this->expect_redirect([$this->settings, 'handle_test_event']);

        $this->assert_same('error', $redirect['query']['website_watchdog_test_status']);
    }

    public function test_test_incident_requires_manage_options_and_nonce(): void
    {
        $this->as_visitor();
        $this->post_with_nonce('website_watchdog_test_incident_nonce', ['incident_details' => 'x']);
        $this->expect_die([$this->settings, 'handle_test_incident']);

        $this->as_admin();
        $this->set_request([], ['incident_details' => 'x']);
        $this->expect_die([$this->settings, 'handle_test_incident']);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_test_incident_requires_details(): void
    {
        $this->as_admin();
        $this->configure_verified_key();
        $this->post_with_nonce('website_watchdog_test_incident_nonce', ['incident_details' => '   ']);

        $redirect = $this->expect_redirect([$this->settings, 'handle_test_incident']);

        $this->assert_same('error', $redirect['query']['website_watchdog_test_status']);
        $this->assert_same('incident', $redirect['query']['website_watchdog_test']);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_test_incident_is_sent_with_severity_and_source(): void
    {
        $this->as_admin();
        $this->configure_verified_key();
        $this->post_with_nonce('website_watchdog_test_incident_nonce', [
            'incident_details' => 'Disk almost full',
            'incident_severity' => 'critical',
            'incident_source' => 'monitor',
        ]);

        $redirect = $this->expect_redirect([$this->settings, 'handle_test_incident']);

        $this->assert_same('success', $redirect['query']['website_watchdog_test_status']);
        $body = $this->last_http_json();
        $this->assert_same('Disk almost full', $body['details']);
        $this->assert_same('critical', $body['severity']);
        $this->assert_same('monitor', $body['source']);
    }

    public function test_test_incident_defaults_source_and_severity(): void
    {
        $this->as_admin();
        $this->configure_verified_key();
        $this->post_with_nonce('website_watchdog_test_incident_nonce', ['incident_details' => 'x', 'incident_severity' => 'bogus', 'incident_source' => '']);

        $this->expect_redirect([$this->settings, 'handle_test_incident']);

        $body = $this->last_http_json();
        $this->assert_same('info', $body['severity']);
        $this->assert_same('on_demand', $body['source']);
    }
}
