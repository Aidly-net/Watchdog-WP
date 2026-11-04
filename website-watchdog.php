<?php

/**
 * Plugin Name: Website Watchdog - PHP Error & Incident Alerts
 * Plugin URI: https://wd.aidly.net/
 * Description: WordPress Agent plugin for Website Watchdog.
 * Version: 1.1.0
 * Author: aildy
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: website-watchdog
 */

defined('ABSPATH') || exit;

if (!defined('WEBSITE_WATCHDOG_VERSION')) {
    define('WEBSITE_WATCHDOG_VERSION', '1.1.0');
}

if (!defined('WEBSITE_WATCHDOG_PATH')) {
    define('WEBSITE_WATCHDOG_PATH', plugin_dir_path(__FILE__));
}

if (!defined('WEBSITE_WATCHDOG_URL')) {
    define('WEBSITE_WATCHDOG_URL', 'https://wd.aidly.net');
}

if (!defined('WEBSITE_WATCHDOG_API_URL')) {
    define('WEBSITE_WATCHDOG_API_URL', WEBSITE_WATCHDOG_URL . '/api');
}

if (!defined('WEBSITE_WATCHDOG_API_VERSION')) {
    define('WEBSITE_WATCHDOG_API_VERSION', 'v1');
}

spl_autoload_register(static function ($class): void {
    $prefix = 'WebsiteWatchdog\\Agent\\';

    if (0 !== strpos($class, $prefix)) {
        return;
    }

    $relative_class = substr($class, strlen($prefix));
    $file = WEBSITE_WATCHDOG_PATH . 'src/' . str_replace('\\', '/', $relative_class) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

// Optional in-site test runner (Tools > Watchdog Self-Test); enable with define('WEBSITE_WATCHDOG_SELFTEST', true) in wp-config.php.
if (defined('WEBSITE_WATCHDOG_SELFTEST') && WEBSITE_WATCHDOG_SELFTEST && is_admin() && is_readable(WEBSITE_WATCHDOG_PATH . 'tests/site/admin-page.php')) {
    require_once WEBSITE_WATCHDOG_PATH . 'tests/site/admin-page.php';
}

register_activation_hook(__FILE__, static function (): void {
    \WebsiteWatchdog\Agent\Api::send_plugin_state_event('plugin_activated');
});

register_deactivation_hook(__FILE__, static function (): void {
    \WebsiteWatchdog\Agent\Api::send_plugin_state_event('plugin_deactivated');
});
add_action('plugins_loaded', static function (): void {
    $plugin = new \WebsiteWatchdog\Agent\Plugin();
    $plugin->register();
});

if (!function_exists('website_watchdog_capture_event')) {
    function website_watchdog_capture_event(string $action, array $details = [], ?string $target = null): bool
    {
        return \WebsiteWatchdog\Agent\Api::capture_event($action, $details, $target);
    }
}

if (!function_exists('website_watchdog_capture_incident')) {
    function website_watchdog_capture_incident(string $details, string $severity = 'error', ?string $source = null): bool
    {
        return \WebsiteWatchdog\Agent\Api::capture_incident($details, $severity, $source);
    }
}
