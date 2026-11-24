<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Http\Queue;
use WebsiteWatchdog\Agent\SelfTest\Sandbox;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class QueueTest extends TestCase
{
    private const KEY = 'website_watchdog_offline_queue';

    public function setUp(): void
    {
        delete_option(self::KEY);
    }

    public function test_add_appends_payloads(): void
    {
        $queue = new Queue();
        $queue->add(['n' => 1]);
        $queue->add(['n' => 2]);

        $this->assert_same([['n' => 1], ['n' => 2]], get_option(self::KEY));
    }

    public function test_add_caps_queue_at_50_dropping_oldest(): void
    {
        $queue = new Queue();
        for ($i = 1; $i <= 55; $i++) {
            $queue->add(['n' => $i]);
        }

        $stored = get_option(self::KEY);
        $this->assert_count(50, $stored);
        $this->assert_same(['n' => 6], $stored[0]);
        $this->assert_same(['n' => 55], $stored[49]);
    }

    public function test_flush_on_empty_queue_makes_no_requests(): void
    {
        $this->assert_same([], (new Queue())->flush());
        $this->assert_count(0, $this->http_calls());
    }

    public function test_flush_keeps_queue_when_key_is_rejected(): void
    {
        $this->set_option('website_watchdog_api_key', 'k');
        $this->set_option(self::KEY, [['n' => 1]]);
        $this->mock_http_response(401, '');

        $this->assert_same([['n' => 1]], (new Queue())->flush());
        $this->assert_same([['n' => 1]], get_option(self::KEY));
        $this->assert_count(1, $this->http_calls());
    }

    public function test_flush_keeps_queue_when_server_unreachable(): void
    {
        $this->set_option('website_watchdog_api_key', 'k');
        $this->set_option(self::KEY, [['n' => 1]]);
        $this->mock_http_error();

        $this->assert_same([['n' => 1]], (new Queue())->flush());
    }

    public function test_flush_delivers_and_clears_queue(): void
    {
        $this->set_option('website_watchdog_api_key', 'k');
        $this->set_option(self::KEY, [['n' => 1], ['n' => 2]]);
        $this->mock_http_response(200, '{"data":{"site_id":"abc"}}');

        $this->assert_same([], (new Queue())->flush());
        $this->assert_same([], get_option(self::KEY, []));
        $this->assert_count(3, $this->http_calls());
        $this->assert_true((bool) get_option('website_watchdog_api_key_verified'));
    }

    public function test_flush_retains_items_that_fail_delivery(): void
    {
        $this->set_option('website_watchdog_api_key', 'k');
        $this->set_option(self::KEY, [['n' => 1]]);
        $this->mock_http(static function (string $url) {
            if (false !== strpos($url, '/sites/verify')) {
                return Sandbox::response(200, '{"data":{"site_id":"abc"}}');
            }

            return new \WP_Error('selftest', 'fail');
        });

        $this->assert_same([['n' => 1]], (new Queue())->flush());
    }
}
