<?php

/**
 * Plugin Name: Website Watchdog - PHP Error & Incident Alerts
 * Plugin URI: https://wd.aidly.net/
 * Description: WordPress Agent plugin for Website Watchdog.
 * Version: 1.2.0
 * Author: aidly
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: website-watchdog
 * Icon URI: assets/icon-256x256.png
 */

defined('ABSPATH') || exit;

if (!defined('WEBSITE_WATCHDOG_VERSION')) {
    define('WEBSITE_WATCHDOG_VERSION', '1.2.0');
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

// If this file is called directly, abort.
if (! defined('WPINC')) {
    die;
}

/**
 * Plugin Update Checker
 */

// Require the update checker library
require_once plugin_dir_path(__FILE__) . 'includes/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v6\PucFactory;

// Initialize the update checker pointing to your GitHub repository releases
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Aidly-net/Watchdog-WP', // Your GitHub repository URL
    __FILE__,                                           // Full path to your main plugin file
    'website-watchdog'                                  // Plugin slug[cite: 2]
);

// Optional: Set which branch to check (defaults to master/main)[cite: 2]
$myUpdateChecker->setBranch('main');

// CRITICAL: Tells PUC to look for an uploaded .zip release asset 
// instead of downloading the default auto-generated GitHub source code zip.
$myUpdateChecker->getVcsApi()->enableReleaseAssets();

// Optional: If your repository is private, pass your GitHub token[cite: 2]
// $myUpdateChecker->setAuthentication( 'your-github-personal-access-token' );

/*
 * Debug
add_action('admin_notices', function () {
    if (! current_user_can('manage_options')) {
        return;
    }

    global $myUpdateChecker;
    if (isset($myUpdateChecker)) {
        $update = $myUpdateChecker->checkForUpdates();

        echo '<div class="notice notice-info"><pre>';
        echo "Update Check Result:\n";
        var_dump($update);
        echo '</pre></div>';
    }
});
*/