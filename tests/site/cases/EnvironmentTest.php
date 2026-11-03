<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Admin\IncidentsView;
use WebsiteWatchdog\Agent\Admin\Settings;
use WebsiteWatchdog\Agent\Cron\Heartbeat;
use WebsiteWatchdog\Agent\Plugin;
use WebsiteWatchdog\Agent\SelfTest\TestCase;
use WebsiteWatchdog\Agent\Trappers\StateChangeListener;

/** Checks the plugin is installed, compatible with this site's PHP/WP versions, and fully wired up. */
class EnvironmentTest extends TestCase
{
    private function readme_field(string $field): string
    {
        $readme = (string) file_get_contents(WEBSITE_WATCHDOG_PATH . 'readme.txt');

        return preg_match('/^' . preg_quote($field, '/') . ':\s*(.+)$/mi', $readme, $m) ? trim($m[1]) : '';
    }

    private function plugin_files(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(WEBSITE_WATCHDOG_PATH . 'src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        $files[] = WEBSITE_WATCHDOG_PATH . 'website-watchdog.php';
        $files[] = WEBSITE_WATCHDOG_PATH . 'uninstall.php';

        return $files;
    }

    public function test_meets_declared_php_requirement(): void
    {
        $required = $this->readme_field('Requires PHP');
        $this->assert_not_empty($required, 'readme.txt must declare "Requires PHP"');
        $this->assert_true(version_compare(PHP_VERSION, $required, '>='), 'PHP ' . PHP_VERSION . ' is below the declared minimum ' . $required);
    }

    public function test_meets_declared_wordpress_requirement(): void
    {
        $required = $this->readme_field('Requires at least');
        $this->assert_not_empty($required, 'readme.txt must declare "Requires at least"');
        $this->assert_true(version_compare(get_bloginfo('version'), $required, '>='), 'WordPress ' . get_bloginfo('version') . ' is below the declared minimum ' . $required);
    }

    public function test_required_php_extensions_are_loaded(): void
    {
        foreach (['json', 'mbstring'] as $extension) {
            $this->assert_true(extension_loaded($extension), 'Missing PHP extension: ' . $extension);
        }
    }

    public function test_version_is_consistent_across_header_constant_and_readme(): void
    {
        $header = (string) file_get_contents(WEBSITE_WATCHDOG_PATH . 'website-watchdog.php');
        $this->assert_same(1, preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', $header, $m), 'Plugin header must declare a Version');

        $this->assert_same($m[1], WEBSITE_WATCHDOG_VERSION, 'Header Version vs WEBSITE_WATCHDOG_VERSION');
        $this->assert_same($m[1], $this->readme_field('Stable tag'), 'Header Version vs readme.txt Stable tag');
    }

    public function test_every_php_file_parses_on_this_php_version(): void
    {
        foreach ($this->plugin_files() as $file) {
            try {
                token_get_all((string) file_get_contents($file), TOKEN_PARSE);
            } catch (\ParseError $e) {
                $this->fail(basename($file) . ' does not parse on PHP ' . PHP_VERSION . ': ' . $e->getMessage());
            }
        }
    }

    public function test_every_class_autoloads(): void
    {
        $src = str_replace('\\', '/', WEBSITE_WATCHDOG_PATH . 'src/');
        foreach ($this->plugin_files() as $file) {
            $normalized = str_replace('\\', '/', $file);
            if (0 !== strpos($normalized, $src)) {
                continue;
            }
            $class = 'WebsiteWatchdog\\Agent\\' . str_replace('/', '\\', substr($normalized, strlen($src), -4));
            $this->assert_true(class_exists($class), 'Class does not autoload: ' . $class);
        }
    }

    public function test_assets_exist(): void
    {
        $this->assert_true(is_readable(WEBSITE_WATCHDOG_PATH . 'assets/js/website-watchdog-frontend.js'), 'Frontend script missing');
    }

    public function test_public_helper_functions_are_defined(): void
    {
        $this->assert_true(function_exists('website_watchdog_capture_event'));
        $this->assert_true(function_exists('website_watchdog_capture_incident'));
    }

    public function test_plugin_registers_all_hooks(): void
    {
        $expected = [
            ['admin_menu', Settings::class],
            ['admin_init', Settings::class],
            ['admin_notices', Settings::class],
            ['admin_post_website_watchdog_test_event', Settings::class],
            ['admin_post_website_watchdog_test_incident', Settings::class],
            ['admin_post_website_watchdog_test_error_handler', Settings::class],
            ['admin_post_website_watchdog_test_exception_handler', Settings::class],
            ['admin_post_website_watchdog_test_shutdown_handler', Settings::class],
            ['admin_post_website_watchdog_refresh_incidents', IncidentsView::class],
            ['upgrader_process_complete', StateChangeListener::class],
            ['activated_plugin', StateChangeListener::class],
            ['deactivated_plugin', StateChangeListener::class],
            ['switch_theme', StateChangeListener::class],
            ['cron_schedules', Heartbeat::class],
            ['website_watchdog_retry_queue', Heartbeat::class],
            ['website_watchdog_heartbeat', Heartbeat::class],
            ['wp_enqueue_scripts', Plugin::class],
        ];

        foreach ($expected as $pair) {
            $this->assert_true($this->hook_has_instance_of($pair[0], $pair[1]), $pair[1] . ' is not hooked to ' . $pair[0] . ' - is the plugin active?');
        }
    }

    public function test_cron_events_are_scheduled(): void
    {
        $this->assert_true((bool) wp_next_scheduled('website_watchdog_heartbeat'), 'Heartbeat event is not scheduled');
        $this->assert_true((bool) wp_next_scheduled('website_watchdog_retry_queue'), 'Retry-queue event is not scheduled');
        $this->assert_array_has_key('15minutes', wp_get_schedules());
    }

    public function test_rest_routes_are_registered(): void
    {
        $routes = rest_get_server()->get_routes();

        $this->assert_array_has_key('/website-watchdog/v1/health', $routes);
        $this->assert_array_has_key('/website-watchdog/v1/js-error', $routes);
    }

    public function test_php_error_handlers_are_installed(): void
    {
        $previous = set_error_handler(static function () {
            return false;
        });
        restore_error_handler();

        if (!is_array($previous) || !is_object($previous[0]) || !($previous[0] instanceof \WebsiteWatchdog\Agent\Trappers\ErrorTrapper)) {
            $this->skip('Another component currently owns the PHP error handler (e.g. a debugging plugin or the test runner).');
        }
        $this->assert_same('handle_error', $previous[1]);
    }
}
