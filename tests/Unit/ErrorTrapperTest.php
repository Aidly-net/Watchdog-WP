<?php

namespace WebsiteWatchdog\Agent\Tests\Unit;

use WebsiteWatchdog\Agent\Http\Client;
use WebsiteWatchdog\Agent\Tests\TestCase;
use WebsiteWatchdog\Agent\Trappers\ErrorTrapper;

class ErrorTrapperTest extends TestCase
{
    private $client;
    private $trapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createMock(Client::class);
        $this->trapper = new ErrorTrapper($this->client);
    }

    public function test_fatal_error_is_dispatched(): void
    {
        $this->client->expects($this->once())->method('dispatch')->with($this->callback(function (array $p): bool {
            return 'incident' === $p['event_type'] && 'critical' === $p['payload']['severity'] && 'php_error' === $p['payload']['error_type'] && 'boom' === $p['payload']['details'] && 'f.php:12' === $p['payload']['source'];
        }));

        $this->assertTrue($this->trapper->handle_error(E_ERROR, 'boom', 'f.php', 12));
    }

    public function test_non_fatal_error_is_ignored(): void
    {
        $this->client->expects($this->never())->method('dispatch');

        $this->assertFalse($this->trapper->handle_error(E_WARNING, 'warn', 'f.php', 1));
    }

    public function test_error_outside_error_reporting_is_ignored(): void
    {
        $previous = error_reporting(0);
        $this->client->expects($this->never())->method('dispatch');

        try {
            $this->assertFalse($this->trapper->handle_error(E_ERROR, 'x'));
        } finally {
            error_reporting($previous);
        }
    }

    public function test_exception_is_dispatched(): void
    {
        $this->client->expects($this->once())->method('dispatch')->with($this->callback(function (array $p): bool {
            return 'incident' === $p['event_type'] && 'error' === $p['payload']['severity'] && 'RuntimeException' === $p['payload']['error_type'] && 'bad' === $p['payload']['details'] && !empty($p['payload']['stack_trace']);
        }));

        $this->trapper->handle_exception(new \RuntimeException('bad'));
    }

    public function test_shutdown_without_error_is_silent(): void
    {
        error_clear_last();
        $this->client->expects($this->never())->method('dispatch');

        $this->trapper->handle_shutdown();
    }

    public function test_shutdown_dispatches_supplied_fatal_error(): void
    {
        $this->client->expects($this->once())->method('dispatch')->with($this->callback(function (array $p): bool {
            return 'php_fatal' === $p['payload']['error_type']
                && 'simulated fatal' === $p['payload']['details']
                && 'f.php:12' === $p['payload']['source'];
        }));

        $this->trapper->handle_shutdown([
            'type' => E_ERROR,
            'message' => 'simulated fatal',
            'file' => 'f.php',
            'line' => 12,
        ]);
    }

    public function test_shutdown_ignores_non_fatal_last_error(): void
    {
        @trigger_error('notice only', E_USER_NOTICE);
        $this->client->expects($this->never())->method('dispatch');

        $this->trapper->handle_shutdown();
        error_clear_last();
    }
}
