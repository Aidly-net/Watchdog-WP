<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\SelfTest\RecordingClient;
use WebsiteWatchdog\Agent\SelfTest\TestCase;
use WebsiteWatchdog\Agent\Trappers\StateChangeListener;

class StateChangeListenerTest extends TestCase
{
    private $client;
    private $listener;

    public function setUp(): void
    {
        $this->client = new RecordingClient();
        $this->listener = new StateChangeListener($this->client);
    }

    private function payload(int $index = 0): array
    {
        return $this->client->dispatched[$index]['payload'];
    }

    public function test_upgrader_events_are_classified(): void
    {
        $cases = [
            [['type' => 'core'], 'core_updated', 'core'],
            [['type' => 'plugin'], 'plugin_updated', 'plugin'],
            [['type' => 'theme'], 'theme_updated', 'theme'],
            [['plugin' => 'a/a.php'], 'plugin_updated', 'a/a.php'],
            [['plugins' => ['a/a.php']], 'plugin_updated', 'a/a.php'],
            [['theme' => 'twenty'], 'theme_updated', 'theme'],
            [[], 'update_completed', 'unknown'],
            [['type' => 'translation'], 'update_completed', 'translation'],
        ];

        foreach ($cases as $index => $case) {
            $this->listener->handle_upgrader_complete(null, $case[0]);
            $payload = $this->payload($index);
            $this->assert_same('state_change', $payload['event_type']);
            $this->assert_same($case[1], $payload['payload']['action'], 'action for case ' . $index);
            $this->assert_same($case[2], $payload['payload']['target'], 'target for case ' . $index);
            foreach ($case[0] as $key => $value) {
                $this->assert_same($value, $payload['payload']['details'][$key]);
            }
        }
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

        $payload = $this->payload()['payload'];
        $this->assert_same('plugin_updated', $payload['action']);
        $this->assert_same('installed-plugin/installed-plugin.php', $payload['target']);
        $this->assert_same('installed-plugin', $payload['details']['plugin_slug']);
    }

    public function test_plugin_update_reports_the_plugin_name(): void
    {
        $plugin = plugin_basename(WEBSITE_WATCHDOG_PATH . 'website-watchdog.php');

        $this->listener->handle_upgrader_complete(null, ['type' => 'plugin', 'plugin' => $plugin]);

        $payload = $this->payload()['payload'];
        $this->assert_same('plugin_updated', $payload['action']);
        $this->assert_contains('Website Watchdog', $payload['target']);
        $this->assert_same(dirname($plugin), $payload['details']['plugin_slug']);
    }

    public function test_plugin_activation_resolves_name_and_slug_from_plugin_header(): void
    {
        $basename = plugin_basename(WEBSITE_WATCHDOG_PATH . 'website-watchdog.php');

        $this->listener->handle_plugin_activation($basename);

        $payload = $this->payload()['payload'];
        $this->assert_same('plugin_activated', $payload['action']);
        $this->assert_contains('Website Watchdog', $payload['target']);
        $this->assert_same(dirname($basename), $payload['details']['plugin_slug']);
    }

    public function test_unknown_plugin_falls_back_to_its_path(): void
    {
        $this->listener->handle_plugin_deactivation('ghost-plugin/ghost.php');
        $this->listener->handle_plugin_deactivation('hello.php');

        $this->assert_same('plugin_deactivated', $this->payload(0)['payload']['action']);
        $this->assert_same('ghost-plugin/ghost.php', $this->payload(0)['payload']['target']);
        $this->assert_same('ghost-plugin', $this->payload(0)['payload']['details']['plugin_slug']);
        $this->assert_same('hello', $this->payload(1)['payload']['details']['plugin_slug']);
    }

    public function test_theme_switch_reports_old_and_new_theme(): void
    {
        $old = wp_get_theme();

        $this->listener->handle_theme_switch('New Theme', null, $old);

        $payload = $this->payload()['payload'];
        $this->assert_same('theme_switched', $payload['action']);
        $this->assert_same('New Theme', $payload['target']);
        $this->assert_same(['old_theme' => $old->get('Name'), 'new_theme' => 'New Theme'], $payload['details']);
    }

    public function test_events_reach_the_service_through_the_default_client(): void
    {
        $this->configure_verified_key();

        (new StateChangeListener())->handle_plugin_activation('ghost/ghost.php');

        $body = $this->last_http_json();
        $this->assert_same('state_change', $body['event_type']);
        $this->assert_same('plugin_activated', $body['payload']['action']);
    }
}
