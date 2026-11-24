<?php

namespace WebsiteWatchdog\Agent\SelfTest\Cases;

use WebsiteWatchdog\Agent\SelfTest\RecordingClient;
use WebsiteWatchdog\Agent\SelfTest\TestCase;
use WebsiteWatchdog\Agent\Trappers\ErrorTrapper;

class ErrorTrapperTest extends TestCase
{
    private $client;
    private $trapper;
    private $previous_reporting;

    public function setUp(): void
    {
        $this->client = new RecordingClient();
        $this->trapper = new ErrorTrapper($this->client);
        $this->previous_reporting = error_reporting(E_ALL);
    }

    public function tearDown(): void
    {
        error_reporting($this->previous_reporting);
    }

    public function test_fatal_error_is_dispatched(): void
    {
        $this->assert_true($this->trapper->handle_error(E_ERROR, 'boom', 'f.php', 12));

        $this->assert_count(1, $this->local_payloads('incidents'));
        $payload = ['event_type' => 'incident', 'timestamp' => gmdate('c'), 'payload' => $this->last_local_payload('incidents')];
        $this->assert_valid_incident($payload);
        $this->assert_same('critical', $payload['payload']['severity']);
        $this->assert_same('php_error', $payload['payload']['error_type']);
        $this->assert_same('boom', $payload['payload']['details']);
        $this->assert_same('f.php:12', $payload['payload']['source']);
    }

    public function test_non_fatal_errors_are_ignored(): void
    {
        foreach ([E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_ERROR] as $severity) {
            $this->assert_false($this->trapper->handle_error($severity, 'x', 'f.php', 1));
        }

        $this->assert_count(0, $this->client->dispatched);
    }

    public function test_errors_hidden_by_error_reporting_are_ignored(): void
    {
        error_reporting(0);

        $this->assert_false($this->trapper->handle_error(E_ERROR, 'x'));
        $this->assert_count(0, $this->client->dispatched);
    }

    public function test_exception_is_dispatched_with_trace(): void
    {
        $this->trapper->handle_exception(new \RuntimeException('bad'));

        $payload = ['event_type' => 'incident', 'timestamp' => gmdate('c'), 'payload' => $this->last_local_payload('incidents')];
        $this->assert_valid_incident($payload);
        $this->assert_same('error', $payload['payload']['severity']);
        $this->assert_same('RuntimeException', $payload['payload']['error_type']);
        $this->assert_same('bad', $payload['payload']['details']);
        $this->assert_true(false !== strpos($payload['payload']['source'], ':'), 'source includes file:line');
        $this->assert_true(!empty($payload['payload']['stack_trace']) || is_array($payload['payload']['stack_trace'] ?? []));
    }

    public function test_shutdown_without_error_is_silent(): void
    {
        error_clear_last();

        $this->trapper->handle_shutdown();

        $this->assert_count(0, $this->client->dispatched);
    }

    public function test_shutdown_ignores_non_fatal_last_error(): void
    {
        @trigger_error('notice only', E_USER_NOTICE); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $this->trapper->handle_shutdown();
        error_clear_last();

        $this->assert_count(0, $this->client->dispatched);
    }

    public function test_default_client_sends_sanitized_event_over_http(): void
    {
        $this->configure_verified_key();
        $trapper = new ErrorTrapper();

        $trapper->handle_exception(new \RuntimeException('Failed at https://site.test/x?token=abc'));

        $body = ['event_type' => 'incident', 'timestamp' => gmdate('c'), 'payload' => $this->last_local_payload('incidents')];
        $this->assert_valid_incident($body);
        $this->assert_same('Failed at https://site.test/x', $body['payload']['details']);
    }
}
