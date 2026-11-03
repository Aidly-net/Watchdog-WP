<?php
/**
 * Lightweight WordPress stand-ins so the plugin classes can be unit tested without a WP install.
 */

define('ABSPATH', __DIR__ . '/');
define('WP_PLUGIN_DIR', __DIR__ . '/plugins');
define('MINUTE_IN_SECONDS', 60);
define('WEBSITE_WATCHDOG_VERSION', '1.0.0');
define('WEBSITE_WATCHDOG_PATH', dirname(__DIR__) . '/');
define('WEBSITE_WATCHDOG_URL', 'https://example.test');
define('WEBSITE_WATCHDOG_API_URL', 'https://example.test/api');
define('WEBSITE_WATCHDOG_API_VERSION', 'v1');

spl_autoload_register(static function (string $class): void {
    $map = [
        'WebsiteWatchdog\\Agent\\Tests\\' => __DIR__ . '/',
        'WebsiteWatchdog\\Agent\\' => dirname(__DIR__) . '/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (0 === strpos($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_readable($file)) {
                require_once $file;
            }
            return;
        }
    }
});

class WP_Error
{
    public $code;
    public $message;

    public function __construct($code = '', $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

class WP_REST_Response
{
    public $data;
    public $status;
    public $headers = [];

    public function __construct($data = null, int $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function header(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    public function get_data()
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

class WP_REST_Request
{
    public $headers = [];
    public $body = '';

    public function __construct(string $body = '', array $headers = [])
    {
        $this->body = $body;
        foreach ($headers as $k => $v) {
            $this->headers[strtolower($k)] = $v;
        }
    }

    public function get_header(string $key): ?string
    {
        return $this->headers[strtolower($key)] ?? null;
    }

    public function get_body(): string
    {
        return $this->body;
    }

    public function get_json_params()
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}

/** In-memory replacement for WordPress global state. */
class WPState
{
    public static $options = [];
    public static $transients = [];
    public static $actions = [];
    public static $filters = [];
    public static $scheduled = [];
    public static $routes = [];
    public static $remote_calls = [];
    /** @var callable|null fn(string $url, array $args): array|WP_Error */
    public static $remote_handler = null;

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$actions = [];
        self::$filters = [];
        self::$scheduled = [];
        self::$routes = [];
        self::$remote_calls = [];
        self::$remote_handler = null;
    }
}

function add_action($hook, $callback, $priority = 10, $args = 1)
{
    WPState::$actions[$hook][] = $callback;
}

function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    WPState::$filters[$hook][] = $callback;
}

function get_option($name, $default = false)
{
    return array_key_exists($name, WPState::$options) ? WPState::$options[$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    WPState::$options[$name] = $value;

    return true;
}

function delete_option($name)
{
    unset(WPState::$options[$name]);

    return true;
}

function get_transient($name)
{
    return WPState::$transients[$name] ?? false;
}

function set_transient($name, $value, $expiration = 0)
{
    WPState::$transients[$name] = $value;

    return true;
}

function delete_transient($name)
{
    unset(WPState::$transients[$name]);

    return true;
}

function wp_remote_post($url, $args = [])
{
    WPState::$remote_calls[] = ['url' => $url, 'args' => $args];

    return WPState::$remote_handler ? (WPState::$remote_handler)($url, $args) : ['response' => ['code' => 200], 'body' => '{}'];
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? ($response['response']['code'] ?? '') : '';
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) ? ($response['body'] ?? '') : '';
}

function wp_json_encode($data, $flags = 0)
{
    return json_encode($data, $flags);
}

function home_url()
{
    return 'https://site.test';
}

function get_bloginfo($show = '')
{
    return '6.5.0';
}

function sanitize_text_field($str)
{
    return trim(strip_tags((string) $str));
}

function sanitize_textarea_field($str)
{
    return trim(strip_tags((string) $str));
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function absint($value)
{
    return abs((int) $value);
}

function __($text, $domain = 'default')
{
    return $text;
}

function wp_next_scheduled($hook)
{
    return WPState::$scheduled[$hook] ?? false;
}

function wp_schedule_event($timestamp, $recurrence, $hook)
{
    WPState::$scheduled[$hook] = $timestamp;

    return true;
}

function register_rest_route($namespace, $route, $args)
{
    WPState::$routes[$namespace . $route] = $args;

    return true;
}

function get_plugin_data($file, $markup = true, $translate = true)
{
    return [];
}
