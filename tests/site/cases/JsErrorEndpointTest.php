<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\Rest\JsErrorEndpoint;
use WebsiteWatchdog\Agent\SelfTest\Sandbox;
use WebsiteWatchdog\Agent\SelfTest\TestCase;

class JsErrorEndpointTest extends TestCase
{
    private const ROUTE = '/website-watchdog/v1/js-error';

    public function setUp(): void
    {
        $_SERVER['REMOTE_ADDR'] = Sandbox::TEST_IP;
        $this->configure_verified_key();
    }

    private function post(array $json): \WP_REST_Response
    {
        return $this->rest('POST', self::ROUTE, ['json' => $json]);
    }

    public function test_route_is_public_and_forwards_error(): void
    {
        $this->as_visitor();

        $response = $this->post(['type' => 'window.onerror', 'message' => 'Boom']);

        $this->assert_status($response, 200);
        $this->assert_true($response->get_data()['ok']);
        $body = ['event_type' => 'incident', 'timestamp' => gmdate('c'), 'payload' => $this->last_local_payload('incidents')];
        $this->assert_valid_incident($body);
        $this->assert_same('js_error:window.onerror', $body['payload']['error_type']);
        $this->assert_same('Boom', $body['payload']['details']);
        $this->assert_same('warning', $body['payload']['severity']);
    }

    public function test_rejects_oversized_body(): void
    {
        $response = $this->post(['message' => str_repeat('a', 20000)]);

        $this->assert_status($response, 413);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_rejects_invalid_json(): void
    {
        $response = $this->rest('POST', self::ROUTE, ['json' => 'not json']);

        $this->assert_status($response, 400);
        $this->assert_count(0, $this->http_calls());
    }

    public function test_accepts_text_plain_body_sent_by_send_beacon(): void
    {
        $this->as_visitor();

        $response = $this->rest('POST', self::ROUTE, [
            'headers' => ['content-type' => 'text/plain;charset=UTF-8'],
            'raw' => '{"type":"window.onerror","message":"Beacon boom","line":1}',
        ]);

        $this->assert_status($response, 200);
        $this->assert_same('Beacon boom', $this->last_local_payload('incidents')['details']);
    }

    public function test_rejects_non_object_json_payload(): void
    {
        $response = $this->rest('POST', self::ROUTE, ['json' => '"just a string"']);

        $this->assert_status($response, 400);
    }

    public function test_rate_limits_after_ten_requests_per_client(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assert_status($this->post(['message' => 'x']), 200);
        }

        $this->assert_status($this->post(['message' => 'x']), 429);

        $_SERVER['REMOTE_ADDR'] = '192.0.2.78';
        $this->assert_status($this->post(['message' => 'x']), 200);
        delete_transient('website_watchdog_js_rate_' . md5('192.0.2.78'));
    }

    public function test_only_allowed_fields_are_forwarded_and_sanitized(): void
    {
        $this->post([
            'type' => 'error',
            'message' => '<b>Oops</b> at https://s.test/a?token=1',
            'line' => '-12',
            'column' => 4,
            'evil' => 'drop me',
            'source' => ['not', 'scalar'],
        ]);

        $payload = ['event_type' => 'incident', 'timestamp' => gmdate('c'), 'payload' => $this->last_local_payload('incidents')];
        $this->assert_valid_incident($payload);
        $payload = $payload['payload'];
        $this->assert_array_not_has_key('evil', $payload);
        $this->assert_same('js_error:error', $payload['error_type']);
        $this->assert_same('Oops at https://s.test/a', $payload['details']);
        $this->assert_same('javascript', $payload['source']);
    }

    public function test_repeated_error_is_reported_once(): void
    {
        $first = $this->post(['message' => 'same error', 'source' => 'a.js', 'line' => 3, 'column' => 1]);
        $second = $this->post(['message' => 'same error', 'source' => 'a.js', 'line' => 3, 'column' => 1]);
        $other = $this->post(['message' => 'different error', 'source' => 'a.js', 'line' => 3, 'column' => 1]);

        $this->assert_status($first, 200);
        $this->assert_status($second, 200);
        $this->assert_status($other, 200);
        $this->assert_count(2, $this->local_payloads('incidents'));
        $this->assert_count(2, $this->http_calls());
    }

    public function test_page_url_and_stack_are_forwarded(): void
    {
        $this->post(['message' => 'with context', 'url' => 'https://site.test/checkout/?token=1', 'stack' => "Error: x\n    at a.js:1:2"]);

        $payload = $this->last_local_payload('incidents');
        $this->assert_same('https://site.test/checkout/', $payload['url']);
        $this->assert_same(['Error: x', 'at a.js:1:2'], $payload['stack_trace']);
    }

    public function test_message_that_fits_is_preserved(): void
    {
        $this->post(['message' => str_repeat('a', 5000)]);

        $this->assert_same(5000, strlen($this->last_local_payload('incidents')['details']));
    }

    public function test_handler_works_with_injected_client(): void
    {
        $client = new \WebsiteWatchdog\Agent\SelfTest\RecordingClient();
        $endpoint = new JsErrorEndpoint($client);
        $request = new \WP_REST_Request('POST', self::ROUTE);
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode(['message' => 'injected']));

        $response = $endpoint->handle_request($request);

        $this->assert_status($response, 200);
        $this->assert_count(0, $client->dispatched);
        $this->assert_same('injected', $this->last_local_payload('incidents')['details']);
    }
}
