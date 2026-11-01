<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Http\Client;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WebsiteWatchdog\Agent\Trappers\StateChangeListener;
use WPState;

class StateChangeListenerTest extends TestCase
{
    private $client;
    private $listener;
    private $payloads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->payloads = [];
        $this->client = $this->createMock(Client::class);
        $this->client->method('dispatch')->willReturnCallback(function (array $p) {
            $this->payloads[] = $p;

            return true;
        });
        $this->listener = new StateChangeListener($this->client);
    }

    public function test_register_hooks_all_events(): void
    {
        $this->listener->register();

        foreach (['upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'switch_theme'] as $hook) {
            $this->assertArrayHasKey($hook, WPState::$actions);
        }
    }

    /**
     * @dataProvider upgraderProvider
     */
    public function test_upgrader_actions(array $extra, string $action, string $target): void
    {
        $this->listener->handle_upgrader_complete(null, $extra);

        $payload = $this->payloads[0]['payload'];
        $this->assertSame('state_change', $this->payloads[0]['event_type']);
        $this->assertSame($action, $payload['action']);
        $this->assertSame($target, $payload['target']);
        if (isset($extra['plugin'])) {
            $this->assertSame('a', $payload['details']['plugin_slug']);
        } elseif (isset($extra['plugins'])) {
            $this->assertSame('a', $payload['details']['plugin_slug']);
        }
    }

    public static function upgraderProvider(): array
    {
        return [
            'core' => [['type' => 'core'], 'core_updated', 'core'],
            'plugin by type' => [['type' => 'plugin'], 'plugin_updated', 'plugin'],
            'theme by type' => [['type' => 'theme'], 'theme_updated', 'theme'],
            'plugin by key' => [['plugin' => 'a/a.php'], 'plugin_updated', 'a/a.php'],
            'plugins by key' => [['plugins' => ['a/a.php']], 'plugin_updated', 'a/a.php'],
            'theme by key' => [['theme' => 'twenty'], 'theme_updated', 'twenty'],
            'themes by key' => [['themes' => ['a', 'b']], 'theme_updated', 'a, b'],
            'unknown' => [[], 'update_completed', 'unknown'],
            'unmapped type' => [['type' => 'translation'], 'update_completed', 'translation'],
        ];
    }

    public function test_plugin_install_uses_upgrader_plugin_info(): void
    {
        $upgrader = new class {
            public function plugin_info(): string
            {
                return 'installed-plugin/installed-plugin.php';
            }
        };

        $this->listener->handle_upgrader_complete($upgrader, ['type' => 'plugin', 'action' => 'install']);

        $payload = $this->payloads[0]['payload'];
        $this->assertSame('plugin_installed', $payload['action']);
        $this->assertSame('installed-plugin/installed-plugin.php', $payload['target']);
        $this->assertSame('installed-plugin', $payload['details']['plugin_slug']);
    }

    public function test_theme_install_reports_theme_name(): void
    {
        $theme = new class {
            public function get(string $key): string
            {
                return 'Twenty Test';
            }

            public function get_stylesheet(): string
            {
                return 'twenty-test';
            }
        };
        $upgrader = new class($theme) {
            private $theme;

            public function __construct($theme)
            {
                $this->theme = $theme;
            }

            public function theme_info()
            {
                return $this->theme;
            }
        };

        $this->listener->handle_upgrader_complete($upgrader, ['type' => 'theme', 'action' => 'install']);

        $payload = $this->payloads[0]['payload'];
        $this->assertSame('theme_installed', $payload['action']);
        $this->assertSame('Twenty Test', $payload['target']);
        $this->assertSame('twenty-test', $payload['details']['theme_slug']);
    }

    public function test_plugin_activation_and_deactivation(): void
    {
        $this->listener->handle_plugin_activation('my-plugin/my-plugin.php');
        $this->listener->handle_plugin_deactivation('hello.php');

        $this->assertSame('plugin_activated', $this->payloads[0]['payload']['action']);
        $this->assertSame('my-plugin/my-plugin.php', $this->payloads[0]['payload']['target']);
        $this->assertSame('my-plugin', $this->payloads[0]['payload']['details']['plugin_slug']);
        $this->assertSame('plugin_deactivated', $this->payloads[1]['payload']['action']);
        $this->assertSame('hello', $this->payloads[1]['payload']['details']['plugin_slug']);
    }

    public function test_theme_switch(): void
    {
        $old = new class {
            public function get(string $key): string
            {
                return 'Old Theme';
            }
        };

        $this->listener->handle_theme_switch('New Theme', null, $old);

        $payload = $this->payloads[0]['payload'];
        $this->assertSame('theme_switched', $payload['action']);
        $this->assertSame('New Theme', $payload['target']);
        $this->assertSame(['old_theme' => 'Old Theme', 'new_theme' => 'New Theme'], $payload['details']);
    }
}
