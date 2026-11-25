<?php
/**
 * Dependency-free test harness that runs INSIDE a real WordPress install.
 *
 * Compatible with PHP 7.4+ and WordPress 6.0+. It never contacts the network (all HTTP is mocked) and restores
 * every option/transient/global it touches after each test. Local storage uses temporary tables.
 */

namespace WebsiteWatchdog\Agent\SelfTest;

use WebsiteWatchdog\Agent\Http\Client;

class SkipException extends \Exception
{
}

class AssertionFailure extends \Exception
{
}

/** Thrown instead of redirecting/exiting so handlers that call wp_safe_redirect() + exit can be tested. */
class RedirectSignal extends \Exception
{
    public $location = '';
}

/** Thrown instead of wp_die() terminating the request. */
class DieSignal extends \Exception
{
    public $status = 0;
}

/** Client double that records dispatches instead of sending them. */
class RecordingClient extends Client
{
    public $dispatched = [];
    public $result = true;

    public function dispatch(array $payload, bool $queue_on_failure = true, string $endpoint = '/' . WEBSITE_WATCHDOG_API_VERSION . '/events/ingest'): bool
    {
        $this->dispatched[] = ['payload' => $payload, 'queue' => $queue_on_failure, 'endpoint' => $endpoint];

        return $this->result;
    }
}

final class Sandbox
{
    const TEST_IP = '192.0.2.77';
    const OPTIONS = [
        'website_watchdog_api_key',
        'website_watchdog_api_key_verified',
        'website_watchdog_site_id',
        'website_watchdog_offline_queue',
    ];
    const TRANSIENTS = [
        'website_watchdog_cooldown',
        'website_watchdog_rate_limit_count',
        'website_watchdog_js_seen',
        'website_watchdog_backoff',
        'website_watchdog_delivery_failures',
        'website_watchdog_event_seen',
    ];
    const GLOBALS_TO_RESTORE = [
        'menu', 'submenu', 'admin_page_hooks', 'wp_settings_sections', 'wp_settings_fields',
        'wp_registered_settings', 'wp_settings_errors',
    ];
    const DIE_HOOKS = ['wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler'];
    const PRE_UPDATE_HOOK = 'pre_update_option_website_watchdog_api_key';

    public static $http_calls = [];
    public static $http_handler = null;
    public static $php_notices = [];

    private static $missing;
    private static $state = [];

    public static function missing()
    {
        if (null === self::$missing) {
            self::$missing = new \stdClass();
        }

        return self::$missing;
    }

    public static function js_rate_transient(): string
    {
        return 'website_watchdog_js_rate_' . md5(self::TEST_IP);
    }

    public static function load_admin_includes(): void
    {
        foreach (['plugin.php', 'template.php'] as $file) {
            if (is_readable(ABSPATH . 'wp-admin/includes/' . $file)) {
                require_once ABSPATH . 'wp-admin/includes/' . $file;
            }
        }
    }

    public static function begin(): void
    {
        global $wp_filter, $wpdb;

        self::load_admin_includes();
        if (!(new \WebsiteWatchdog\Agent\Local\Store())->install()) {
            throw new \RuntimeException('Local incident storage is unavailable.');
        }
        foreach (['watchdog_incidents', 'watchdog_changes', 'watchdog_outbox'] as $name) {
            $table = $wpdb->prefix . $name;
            $definition = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            $sql = is_array($definition) ? preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $definition[1]) : null;
            if (!$sql || false === $wpdb->query($sql)) {
                throw new \RuntimeException('Could not isolate local test storage: ' . $wpdb->last_error);
            }
        }
        self::$http_calls = [];
        self::$http_handler = null;
        self::$php_notices = [];

        $state = ['options' => [], 'transients' => [], 'globals' => [], 'super' => [], 'user' => get_current_user_id()];

        foreach (self::OPTIONS as $name) {
            $state['options'][$name] = get_option($name, self::missing());
        }

        $transients = array_merge(self::TRANSIENTS, [self::js_rate_transient()]);
        foreach ($transients as $name) {
            $value = get_transient($name);
            if (false !== $value) {
                $timeout = wp_using_ext_object_cache() ? 0 : (int) get_option('_transient_timeout_' . $name, 0);
                $state['transients'][$name] = [$value, $timeout > time() ? $timeout - time() : 60];
            }
            delete_transient($name);
        }

        foreach (self::GLOBALS_TO_RESTORE as $name) {
            $state['globals'][$name] = array_key_exists($name, $GLOBALS) ? $GLOBALS[$name] : self::missing();
        }

        foreach (['_GET', '_POST', '_REQUEST', '_SERVER'] as $name) {
            $state['super'][$name] = $GLOBALS[$name];
        }

        // Settings::validate_api_key_option() is hooked live on admin requests; keep it out of the way during tests.
        $state['pre_update_hook'] = isset($wp_filter[self::PRE_UPDATE_HOOK]) ? $wp_filter[self::PRE_UPDATE_HOOK] : null;
        unset($wp_filter[self::PRE_UPDATE_HOOK]);

        self::$state = $state;

        add_filter('pre_http_request', [__CLASS__, 'intercept_http'], -9999, 3);
        add_filter('wp_redirect', [__CLASS__, 'intercept_redirect'], -9999, 2);
        foreach (self::DIE_HOOKS as $hook) {
            add_filter($hook, [__CLASS__, 'die_handler_factory'], 9999);
        }

        set_error_handler([__CLASS__, 'capture_php_notice']);
    }

    public static function end(): void
    {
        global $wp_filter, $wpdb;

        restore_error_handler();
        remove_filter('pre_http_request', [__CLASS__, 'intercept_http'], -9999);
        remove_filter('wp_redirect', [__CLASS__, 'intercept_redirect'], -9999);
        foreach (self::DIE_HOOKS as $hook) {
            remove_filter($hook, [__CLASS__, 'die_handler_factory'], 9999);
        }

        $state = self::$state;

        // Options are restored while the live validation filter is still detached.
        foreach ($state['options'] as $name => $value) {
            if ($value === self::missing()) {
                delete_option($name);
            } else {
                update_option($name, $value, false);
            }
        }

        if (null !== $state['pre_update_hook']) {
            $wp_filter[self::PRE_UPDATE_HOOK] = $state['pre_update_hook'];
        } else {
            unset($wp_filter[self::PRE_UPDATE_HOOK]);
        }

        $transients = array_merge(self::TRANSIENTS, [self::js_rate_transient()]);
        foreach ($transients as $name) {
            delete_transient($name);
        }
        foreach ($state['transients'] as $name => $pair) {
            set_transient($name, $pair[0], $pair[1]);
        }

        foreach ($state['globals'] as $name => $value) {
            if ($value === self::missing()) {
                unset($GLOBALS[$name]);
            } else {
                $GLOBALS[$name] = $value;
            }
        }

        foreach ($state['super'] as $name => $value) {
            $GLOBALS[$name] = $value;
        }

        wp_set_current_user($state['user']);
        foreach (['watchdog_incidents', 'watchdog_changes', 'watchdog_outbox'] as $name) {
            $table = $wpdb->prefix . $name;
            if (false === $wpdb->query("DROP TEMPORARY TABLE `$table`")) {
                throw new \RuntimeException('Could not remove local test storage.');
            }
        }
        self::$http_handler = null;
    }

    public static function capture_php_notice($errno, $errstr, $errfile = '', $errline = 0): bool
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $plugin_src = str_replace('\\', '/', WEBSITE_WATCHDOG_PATH . 'src/');
        if (0 === strpos(str_replace('\\', '/', (string) $errfile), $plugin_src)) {
            self::$php_notices[] = sprintf('PHP error %d "%s" at %s:%d', $errno, $errstr, basename((string) $errfile), $errline);
        }

        return true;
    }

    public static function response(int $code, string $body = '', array $headers = []): array
    {
        return [
            'headers' => $headers,
            'body' => $body,
            'response' => ['code' => $code, 'message' => ''],
            'cookies' => [],
            'filename' => null,
        ];
    }

    public static function intercept_http($preempt, $args, $url)
    {
        self::$http_calls[] = ['url' => (string) $url, 'args' => is_array($args) ? $args : []];

        if (is_callable(self::$http_handler)) {
            $result = call_user_func(self::$http_handler, (string) $url, $args);
            if (null !== $result) {
                return $result;
            }
        }

        return self::response(200, '{}');
    }

    public static function intercept_redirect($location, $status = 302)
    {
        $signal = new RedirectSignal('Redirect to ' . $location);
        $signal->location = (string) $location;

        throw $signal;
    }

    public static function die_handler_factory($handler)
    {
        return [__CLASS__, 'die_handler'];
    }

    public static function die_handler($message = '', $title = '', $args = [])
    {
        if ($message instanceof \WP_Error) {
            $message = $message->get_error_message();
        }
        $signal = new DieSignal('wp_die: ' . wp_strip_all_tags((string) $message));
        $signal->status = is_array($args) && isset($args['response']) ? (int) $args['response'] : (is_int($title) ? $title : 0);

        throw $signal;
    }

    public static function admin_user_id(): int
    {
        $ids = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID']);
        if (!empty($ids)) {
            return (int) $ids[0];
        }

        $supers = function_exists('get_super_admins') ? get_super_admins() : [];
        foreach ($supers as $login) {
            $user = get_user_by('login', $login);
            if ($user) {
                return (int) $user->ID;
            }
        }

        return 0;
    }

    public static function export($value): string
    {
        if (is_object($value)) {
            return get_class($value);
        }

        $out = var_export($value, true);

        return strlen($out) > 300 ? substr($out, 0, 300) . '...' : $out;
    }
}

abstract class TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    // ---- assertions -------------------------------------------------------------------------------------------

    protected function fail(string $message): void
    {
        throw new AssertionFailure($message);
    }

    protected function skip(string $reason): void
    {
        throw new SkipException($reason);
    }

    protected function assert_true($actual, string $message = ''): void
    {
        if (true !== $actual) {
            $this->fail(($message ?: 'Expected true') . ', got ' . Sandbox::export($actual));
        }
    }

    protected function assert_false($actual, string $message = ''): void
    {
        if (false !== $actual) {
            $this->fail(($message ?: 'Expected false') . ', got ' . Sandbox::export($actual));
        }
    }

    protected function assert_same($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->fail(($message ? $message . ': ' : '') . 'Expected ' . Sandbox::export($expected) . ', got ' . Sandbox::export($actual));
        }
    }

    protected function assert_null($actual, string $message = ''): void
    {
        $this->assert_same(null, $actual, $message);
    }

    protected function assert_not_empty($actual, string $message = ''): void
    {
        if (empty($actual)) {
            $this->fail(($message ?: 'Expected a non-empty value') . ', got ' . Sandbox::export($actual));
        }
    }

    protected function assert_count(int $expected, $actual, string $message = ''): void
    {
        $this->assert_same($expected, is_countable($actual) ? count($actual) : -1, $message ?: 'Unexpected count');
    }

    protected function assert_contains($needle, $haystack, string $message = ''): void
    {
        $found = is_array($haystack) ? in_array($needle, $haystack, true) : (is_string($haystack) && false !== strpos($haystack, (string) $needle));
        if (!$found) {
            $shown = is_string($haystack) ? Sandbox::export(substr($haystack, 0, 200)) : Sandbox::export($haystack);
            $this->fail(($message ?: 'Failed asserting that value contains ' . Sandbox::export($needle)) . ' (in ' . $shown . ')');
        }
    }

    protected function assert_not_contains($needle, $haystack, string $message = ''): void
    {
        $found = is_array($haystack) ? in_array($needle, $haystack, true) : (is_string($haystack) && false !== strpos($haystack, (string) $needle));
        if ($found) {
            $this->fail($message ?: 'Failed asserting that value does not contain ' . Sandbox::export($needle));
        }
    }

    protected function assert_matches(string $pattern, string $actual, string $message = ''): void
    {
        if (1 !== preg_match($pattern, $actual)) {
            $this->fail($message ?: 'Expected ' . Sandbox::export($actual) . ' to match ' . $pattern);
        }
    }

    protected function assert_array_has_key($key, $array, string $message = ''): void
    {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            $this->fail($message ?: 'Expected array to have key ' . Sandbox::export($key));
        }
    }

    protected function assert_array_not_has_key($key, $array, string $message = ''): void
    {
        if (is_array($array) && array_key_exists($key, $array)) {
            $this->fail($message ?: 'Expected array NOT to have key ' . Sandbox::export($key));
        }
    }

    protected function assert_instance_of(string $class, $actual, string $message = ''): void
    {
        if (!($actual instanceof $class)) {
            $this->fail(($message ?: 'Expected instance of ' . $class) . ', got ' . Sandbox::export($actual));
        }
    }

    // ---- WordPress state helpers --------------------------------------------------------------------------------

    protected function set_option(string $name, $value): void
    {
        update_option($name, $value, false);
    }

    protected function configure_verified_key(string $key = 'selftest-key'): void
    {
        $this->set_option('website_watchdog_api_key', $key);
        $this->set_option('website_watchdog_api_key_verified', true);
        $this->set_option('website_watchdog_site_id', 'selftest-site');
    }

    protected function as_admin(): void
    {
        $id = Sandbox::admin_user_id();
        if (!$id) {
            $this->skip('No administrator user available on this site.');
        }
        wp_set_current_user($id);
    }

    protected function as_visitor(): void
    {
        wp_set_current_user(0);
    }

    // ---- HTTP mocking -------------------------------------------------------------------------------------------

    /** @param callable $handler fn(string $url, array $args): array|\WP_Error|null (null = default 200 "{}") */
    protected function mock_http(callable $handler): void
    {
        Sandbox::$http_handler = $handler;
    }

    protected function mock_http_response(int $code, string $body = '{}'): void
    {
        $this->mock_http(static function () use ($code, $body) {
            return Sandbox::response($code, $body);
        });
    }

    protected function mock_http_error(string $message = 'Simulated network failure'): void
    {
        $this->mock_http(static function () use ($message) {
            return new \WP_Error('selftest_http_error', $message);
        });
    }

    protected function http_calls(): array
    {
        return Sandbox::$http_calls;
    }

    protected function last_http_call(): array
    {
        $calls = Sandbox::$http_calls;
        if (empty($calls)) {
            $this->fail('Expected an HTTP request but none was made.');
        }

        return end($calls);
    }

    protected function last_http_json(): array
    {
        $call = $this->last_http_call();
        $decoded = json_decode(isset($call['args']['body']) ? (string) $call['args']['body'] : '', true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function local_payloads(string $table): array
    {
        global $wpdb;
        if (!in_array($table, ['incidents', 'changes'], true)) {
            $this->fail('Invalid local test table.');
        }
        $rows = $wpdb->get_col('SELECT payload FROM ' . $wpdb->prefix . 'watchdog_' . $table . ' ORDER BY ' . ('incidents' === $table ? 'first_seen, id' : 'id'));
        return array_map(static function ($json) {
            return json_decode($json, true);
        }, $rows);
    }

    protected function last_local_payload(string $table): array
    {
        $payloads = $this->local_payloads($table);
        if (!$payloads) {
            $this->fail('Expected a local record but none was found.');
        }
        return end($payloads);
    }

    // ---- request / output helpers -------------------------------------------------------------------------------

    protected function set_request(array $get = [], array $post = []): void
    {
        $_GET = $get;
        $_POST = $post;
        $_REQUEST = array_merge($get, $post);
    }

    protected function post_with_nonce(string $nonce_action, array $fields = []): void
    {
        $fields['_wpnonce'] = wp_create_nonce($nonce_action);
        $this->set_request([], wp_slash($fields));
    }

    protected function capture(callable $callback): string
    {
        ob_start();
        try {
            $callback();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /** Runs a handler that is expected to wp_safe_redirect() + exit; returns the parsed redirect target. */
    protected function expect_redirect(callable $callback): array
    {
        try {
            $this->capture($callback);
        } catch (RedirectSignal $signal) {
            $query = [];
            wp_parse_str((string) wp_parse_url($signal->location, PHP_URL_QUERY), $query);

            return ['location' => $signal->location, 'query' => $query];
        } catch (DieSignal $signal) {
            $this->fail('Expected a redirect but the handler called ' . $signal->getMessage());
        }

        $this->fail('Expected the handler to redirect, but it returned normally.');

        return [];
    }

    /** Runs a handler that is expected to call wp_die(); returns the die message. */
    protected function expect_die(callable $callback): string
    {
        try {
            $this->capture($callback);
        } catch (DieSignal $signal) {
            return $signal->getMessage();
        } catch (RedirectSignal $signal) {
            $this->fail('Expected wp_die() but the handler redirected to ' . $signal->location);
        }

        $this->fail('Expected the handler to call wp_die(), but it returned normally.');

        return '';
    }

    protected function rest(string $method, string $route, array $options = []): \WP_REST_Response
    {
        rest_get_server();

        $request = new \WP_REST_Request($method, $route);
        if (isset($options['json'])) {
            $request->set_header('content-type', 'application/json');
            $request->set_body(is_string($options['json']) ? $options['json'] : wp_json_encode($options['json']));
        }
        $headers = isset($options['headers']) ? $options['headers'] : [];
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        if (isset($options['raw'])) {
            $request->set_body($options['raw']);
        }

        return rest_do_request($request);
    }

    /** Mirrors the backend incident schema: severity, source and details are required; the rest is optional but typed. */
    protected function assert_valid_incident(array $body): void
    {
        $this->assert_same('incident', $body['event_type'] ?? null, 'event_type');
        $this->assert_true(!empty($body['timestamp']), 'timestamp');
        $payload = isset($body['payload']) && is_array($body['payload']) ? $body['payload'] : [];
        $this->assert_true(in_array($payload['severity'] ?? null, ['info', 'warning', 'error', 'critical'], true), 'payload.severity must be info|warning|error|critical');
        foreach (['source', 'details'] as $field) {
            $this->assert_true(isset($payload[$field]) && is_string($payload[$field]) && '' !== $payload[$field], 'payload.' . $field . ' must be a non-empty string');
        }
        foreach (['error_type', 'url', 'php_version', 'wp_version'] as $field) {
            $this->assert_true(!isset($payload[$field]) || (is_string($payload[$field]) && '' !== $payload[$field]), 'payload.' . $field . ' must be a non-empty string when present');
        }
        if (isset($payload['stack_trace'])) {
            $this->assert_true(is_array($payload['stack_trace']), 'payload.stack_trace must be an array');
            foreach ($payload['stack_trace'] as $entry) {
                $this->assert_true(is_string($entry) && '' !== $entry, 'stack_trace entries must be non-empty strings');
            }
        }
    }

    protected function assert_status(\WP_REST_Response $response, int $expected): void
    {
        $this->assert_same($expected, $response->get_status(), 'HTTP status');
    }

    /** True if $hook has a callback that is a method of an instance of $class. */
    protected function hook_has_instance_of(string $hook, string $class, ?string $method = null): bool
    {
        global $wp_filter;

        if (!isset($wp_filter[$hook])) {
            return false;
        }

        $callbacks = is_object($wp_filter[$hook]) ? $wp_filter[$hook]->callbacks : $wp_filter[$hook];
        foreach ($callbacks as $priority_callbacks) {
            foreach ($priority_callbacks as $callback) {
                $function = $callback['function'];
                if (is_array($function) && is_object($function[0]) && $function[0] instanceof $class && (null === $method || $function[1] === $method)) {
                    return true;
                }
            }
        }

        return false;
    }
}
