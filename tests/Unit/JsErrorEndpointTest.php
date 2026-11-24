<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Http\Client;
use WebsiteWatchdog\Agent\Local\Store;
use WebsiteWatchdog\Agent\Rest\JsErrorEndpoint;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WP_REST_Request;
use WPState;

class JsErrorEndpointTest extends TestCase
{
    private $client;
    private $store;
    private $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->client = $this->createMock(Client::class);
        $this->store = $this->createMock(Store::class);
        $this->endpoint = new JsErrorEndpoint($this->client, $this->store);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    public function test_register_adds_public_post_route(): void
    {
        $this->endpoint->register();
        foreach (WPState::$actions['rest_api_init'] as $callback) {
            $callback();
        }

        $route = WPState::$routes['website-watchdog/v1/js-error'];
        $this->assertSame('POST', $route['methods']);
        $this->assertSame('__return_true', $route['permission_callback']);
    }

    public function test_rejects_oversized_body(): void
    {
        $this->store->expects($this->never())->method('capture');

        $response = $this->endpoint->handle_request(new WP_REST_Request(str_repeat('a', 16385)));

        $this->assertSame(413, $response->get_status());
    }

    public function test_rejects_invalid_json(): void
    {
        $this->store->expects($this->never())->method('capture');

        $this->assertSame(400, $this->endpoint->handle_request(new WP_REST_Request('not json'))->get_status());
    }

    public function test_rate_limits_after_ten_requests(): void
    {
        $this->store->method('capture')->willReturn(true);
        $request = new WP_REST_Request('{"message":"x"}');

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(200, $this->endpoint->handle_request($request)->get_status());
        }

        $this->assertSame(429, $this->endpoint->handle_request($request)->get_status());
    }

    public function test_forwards_only_allowed_sanitized_fields(): void
    {
        $captured = null;
        $this->store->expects($this->once())->method('capture')->willReturnCallback(function (array $payload) use (&$captured) {
            $captured = $payload;

            return true;
        });

        $body = json_encode([
            'type' => 'error',
            'message' => '<b>Oops</b> at https://s.test/a?token=1',
            'line' => '-12',
            'column' => 4,
            'evil' => 'drop me',
            'source' => ['array'],
        ]);

        $this->assertSame(200, $this->endpoint->handle_request(new WP_REST_Request($body))->get_status());

        $this->assertSame('incident', $captured['event_type']);
        $payload = $captured['payload'];
        $this->assertArrayNotHasKey('evil', $payload);
        $this->assertSame('js_error:error', $payload['error_type']);
        $this->assertSame('warning', $payload['severity']);
        $this->assertSame('Oops at https://s.test/a', $payload['details']);
        $this->assertSame('javascript', $payload['source']);
        $this->assertNotEmpty($payload['url']);
        $this->assertNotEmpty($payload['php_version']);
        $this->assertNotEmpty($payload['wp_version']);
    }

    public function test_repeated_error_is_dispatched_once(): void
    {
        $this->store->expects($this->once())->method('capture')->willReturn(true);
        $request = new WP_REST_Request('{"message":"same","source":"a.js","line":1}');

        $this->assertSame(200, $this->endpoint->handle_request($request)->get_status());
        $this->assertSame(200, $this->endpoint->handle_request($request)->get_status());
    }

    public function test_failed_capture_is_not_reported_as_success_or_deduplicated(): void
    {
        $this->store->expects($this->exactly(2))->method('capture')->willReturn(false);
        $request = new WP_REST_Request('{"message":"same","source":"a.js","line":1}');

        $this->assertSame(503, $this->endpoint->handle_request($request)->get_status());
        $this->assertSame(503, $this->endpoint->handle_request($request)->get_status());
    }

    public function test_preserves_message_that_fits_event_budget(): void
    {
        $captured = null;
        $this->store->method('capture')->willReturnCallback(function (array $payload) use (&$captured) {
            $captured = $payload;

            return true;
        });

        $this->endpoint->handle_request(new WP_REST_Request(json_encode(['message' => str_repeat('a', 5000)])));

        $this->assertSame(5000, strlen($captured['payload']['details']));
    }

    public function test_long_multibyte_message_is_preserved(): void
    {
        $captured = null;
        $this->store->method('capture')->willReturnCallback(function (array $payload) use (&$captured) {
            $captured = $payload;

            return true;
        });
        $message = str_repeat('a', 1999) . "\xF0\x9F\x98\x80";

        $this->endpoint->handle_request(new WP_REST_Request(json_encode(['message' => $message])));

        $this->assertNotFalse(wp_json_encode($captured));
        $this->assertSame($message, $captured['payload']['details']);
    }

    public function test_truncates_stack_trace_middle_preserving_first_and_last_frames(): void
    {
        $captured = null;
        $this->store->method('capture')->willReturnCallback(function (array $payload) use (&$captured) {
            $captured = $payload;

            return true;
        });

        $frames = [];
        for ($index = 1; $index <= 30; $index++) {
            $frames[] = 'frame-' . $index . '-' . str_repeat('x', 100);
        }

        $this->endpoint->handle_request(new WP_REST_Request(json_encode(['message' => 'error', 'stack' => implode("\n", $frames)])));

        $this->assertSame(
            array_merge(array_slice($frames, 0, 10), ['.........'], array_slice($frames, -5)),
            $captured['payload']['stack_trace']
        );
    }
}
