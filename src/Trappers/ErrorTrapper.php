<?php

namespace WebsiteWatchdog\Agent\Trappers;

use Throwable;
use WebsiteWatchdog\Agent\Http\Client;
use WebsiteWatchdog\Agent\IncidentPayload;

class ErrorTrapper
{
    protected $http_client;
    protected $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    public function __construct(?Client $http_client = null)
    {
        $this->http_client = $http_client ?: new Client();
    }

    public function register(): void
    {
        // Capturing PHP errors is the core purpose of this plugin.
        set_error_handler([$this, 'handle_error']); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
        set_exception_handler([$this, 'handle_exception']);
        register_shutdown_function([$this, 'handle_shutdown']);
    }

    public function handle_error(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // Read-only check to honor the site's error_reporting level and @-suppression.
        if (!(error_reporting() & $severity)) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
            return false;
        }

        if (!in_array($severity, $this->fatal_types, true)) {
            return false;
        }

        $this->dispatch(IncidentPayload::build('critical', IncidentPayload::location($file, $line), $message, ['error_type' => 'php_error']));

        return true;
    }

    public function handle_exception(Throwable $exception): void
    {
        $this->dispatch(IncidentPayload::build(
            'error',
            IncidentPayload::location($exception->getFile(), $exception->getLine()),
            $exception->getMessage(),
            ['error_type' => get_class($exception), 'stack_trace' => IncidentPayload::format_trace($exception->getTrace())]
        ));
    }

    public function handle_shutdown(?array $error = null): void
    {
        $error = null === $error ? error_get_last() : $error;

        if (!is_array($error)) {
            return;
        }

        if (!in_array($error['type'], $this->fatal_types, true)) {
            return;
        }

        $this->dispatch(IncidentPayload::build(
            'critical',
            IncidentPayload::location((string) ($error['file'] ?? ''), (int) ($error['line'] ?? 0)),
            (string) ($error['message'] ?? ''),
            ['error_type' => 'php_fatal']
        ));
    }

    protected function dispatch(array $payload): void
    {
        $this->http_client->dispatch($payload);
    }
}
