<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Http\Queue;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WP_Error;
use WPState;

class QueueTest extends TestCase
{
    private const KEY = 'website_watchdog_offline_queue';

    public function test_add_appends_payloads(): void
    {
        $queue = new Queue();
        $queue->add(['n' => 1]);
        $queue->add(['n' => 2]);

        $this->assertSame([['n' => 1], ['n' => 2]], WPState::$options[self::KEY]);
    }

    public function test_add_caps_queue_at_50_dropping_oldest(): void
    {
        $queue = new Queue();
        for ($i = 1; $i <= 55; $i++) {
            $queue->add(['n' => $i]);
        }

        $this->assertCount(50, WPState::$options[self::KEY]);
        $this->assertSame(['n' => 6], WPState::$options[self::KEY][0]);
        $this->assertSame(['n' => 55], WPState::$options[self::KEY][49]);
    }

    public function test_flush_on_empty_queue_returns_empty(): void
    {
        $this->assertSame([], (new Queue())->flush());
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_flush_keeps_queue_when_key_invalid(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options[self::KEY] = [['n' => 1]];
        WPState::$remote_handler = static function () {
            return ['response' => ['code' => 401], 'body' => ''];
        };

        $this->assertSame([['n' => 1]], (new Queue())->flush());
        $this->assertSame([['n' => 1]], WPState::$options[self::KEY]);
    }

    public function test_flush_delivers_and_clears_queue(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options[self::KEY] = [['n' => 1], ['n' => 2]];
        WPState::$remote_handler = static function (string $url) {
            return ['response' => ['code' => 200], 'body' => '{"data":{"site_id":"abc"}}'];
        };

        $this->assertSame([], (new Queue())->flush());
        $this->assertArrayNotHasKey(self::KEY, WPState::$options);
        // 1 verify + 2 deliveries
        $this->assertCount(3, WPState::$remote_calls);
    }

    public function test_flush_retains_failed_items(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options[self::KEY] = [['n' => 1]];
        WPState::$remote_handler = static function (string $url) {
            if (false !== strpos($url, '/sites/verify')) {
                return ['response' => ['code' => 200], 'body' => '{"data":{"site_id":"abc"}}'];
            }

            return new WP_Error('http', 'fail');
        };

        $this->assertSame([['n' => 1]], (new Queue())->flush());
    }

    public function test_flush_skips_network_during_backoff(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options[self::KEY] = [['n' => 1]];
        WPState::$transients['website_watchdog_backoff'] = true;

        $this->assertSame([['n' => 1]], (new Queue())->flush());
        $this->assertSame([], WPState::$remote_calls);
    }

    public function test_flush_sends_bounded_batch_and_stops_on_first_failure(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options[self::KEY] = array_map(static function (int $n): array {
            return ['n' => $n];
        }, range(1, 25));
        WPState::$remote_handler = static function (string $url) {
            return ['response' => ['code' => 200], 'body' => '{"data":{"site_id":"abc"}}'];
        };

        $remaining = (new Queue())->flush();

        $this->assertCount(15, $remaining);
        $this->assertSame(['n' => 11], $remaining[0]);

        WPState::$remote_calls = [];
        WPState::$remote_handler = static function (string $url) {
            return false !== strpos($url, '/sites/verify')
                ? ['response' => ['code' => 200], 'body' => '{"data":{"site_id":"abc"}}']
                : new WP_Error('http', 'fail');
        };

        $this->assertCount(15, (new Queue())->flush());
        // 1 verify + 1 failed delivery, then stop.
        $this->assertCount(2, WPState::$remote_calls);
        $this->assertNotEmpty(WPState::$transients['website_watchdog_backoff']);
    }

    public function test_flush_drops_rejected_payloads(): void
    {
        WPState::$options['website_watchdog_api_key'] = 'k';
        WPState::$options[self::KEY] = [['n' => 1]];
        WPState::$remote_handler = static function (string $url) {
            return false !== strpos($url, '/sites/verify')
                ? ['response' => ['code' => 200], 'body' => '{"data":{"site_id":"abc"}}']
                : ['response' => ['code' => 422], 'body' => ''];
        };

        $this->assertSame([], (new Queue())->flush());
    }

    public function test_add_caps_queue_by_bytes(): void
    {
        $queue = new Queue();
        for ($i = 0; $i < 20; $i++) {
            $queue->add(['blob' => str_repeat('a', 30000)]);
        }

        $this->assertLessThanOrEqual(262144, strlen(json_encode(WPState::$options[self::KEY])));
        $this->assertGreaterThan(0, count(WPState::$options[self::KEY]));
    }
}
