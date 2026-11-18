<?php

namespace WebsiteWatchdog\Agent;

use WebsiteWatchdog\Agent\Http\PayloadLimiter;

/**
 * Builds incident events in the unified contract shared with on-demand incidents:
 * severity, source and details are required; error_type, url, php_version, wp_version and stack_trace are optional context.
 */
class IncidentPayload
{
    private const MAX_STACK_TRACE_BYTES = 8192;
    private const MAX_FIELD_JSON_BYTES = 2002;

    public static function build(string $severity, string $source, string $details, array $extra = []): array
    {
        $payload = [
            'severity' => in_array($severity, ['info', 'warning', 'error', 'critical'], true) ? $severity : 'error',
            'source' => '' !== $source ? $source : 'unknown',
            'details' => '' !== $details ? $details : 'Unknown error',
            'php_version' => PHP_VERSION,
            'wp_version' => (string) get_bloginfo('version'),
            'url' => isset($extra['url']) && '' !== $extra['url'] ? (string) $extra['url'] : self::current_url(),
        ];

        if (isset($extra['error_type']) && '' !== $extra['error_type']) {
            $payload['error_type'] = (string) $extra['error_type'];
        }

        foreach (['source', 'url', 'error_type', 'php_version', 'wp_version'] as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = PayloadLimiter::limit_string($payload[$field], self::MAX_FIELD_JSON_BYTES);
            }
        }

        $stack_trace = isset($extra['stack_trace']) && is_array($extra['stack_trace']) ? $extra['stack_trace'] : [];
        $stack_trace = array_values(array_filter(array_map('strval', $stack_trace), static function (string $entry): bool {
            return '' !== $entry;
        }));
        $stack_trace = self::truncate_stack_trace($stack_trace);
        if ($stack_trace) {
            $payload['stack_trace'] = $stack_trace;
        }

        return [
            'event_type' => 'incident',
            'timestamp' => gmdate('c'),
            'payload' => Sanitizer::clean_payload($payload),
        ];
    }

    private static function truncate_stack_trace(array $stack_trace): array
    {
        if (count($stack_trace) > 15) {
            $stack_trace = array_merge(array_slice($stack_trace, 0, 10), ['.........'], array_slice($stack_trace, -5));
        }

        $frame_count = count($stack_trace);
        if (0 === $frame_count) {
            return $stack_trace;
        }

        $max_frame_bytes = min(self::MAX_FIELD_JSON_BYTES, intdiv(self::MAX_STACK_TRACE_BYTES - $frame_count - 1, $frame_count));
        foreach ($stack_trace as &$frame) {
            $frame = PayloadLimiter::limit_string($frame, $max_frame_bytes);
        }
        unset($frame);

        return $stack_trace;
    }

    /** "path/to/file.php:42", relative to the WordPress root so server paths aren't disclosed. */
    public static function location(string $file, int $line): string
    {
        if ('' === $file) {
            return '';
        }
        if (defined('ABSPATH') && 0 === strpos($file, ABSPATH)) {
            $file = substr($file, strlen(ABSPATH));
        }

        return $file . ($line > 0 ? ':' . $line : '');
    }

    /** Formats Throwable::getTrace() frames like Throwable::getTraceAsString(). */
    public static function format_trace(array $trace): array
    {
        $lines = [];
        foreach ($trace as $index => $frame) {
            if (!is_array($frame)) {
                continue;
            }
            $call = (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '') . ($frame['function'] ?? '');
            $location = isset($frame['file']) ? self::location((string) $frame['file'], (int) ($frame['line'] ?? 0)) : '[internal function]';
            $lines[] = '#' . $index . ' ' . $location . ': ' . $call . '()';
        }

        return $lines;
    }

    private static function current_url(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        if ('' === $uri || '' === $host) {
            return home_url('/');
        }

        return (is_ssl() ? 'https://' : 'http://') . $host . $uri;
    }
}
