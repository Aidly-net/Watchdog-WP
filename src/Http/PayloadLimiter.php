<?php

namespace WebsiteWatchdog\Agent\Http;

class PayloadLimiter
{
    public const MAX_BYTES = 32768;
    private const MAX_STRING = 2000;
    private const MAX_LIST = 50;

    /** Truncates oversized strings and lists; returns null when the payload still exceeds MAX_BYTES. */
    public static function limit(array $payload): ?array
    {
        $payload = self::truncate($payload);
        $json = wp_json_encode($payload);

        return false !== $json && strlen($json) <= self::MAX_BYTES ? $payload : null;
    }

    private static function truncate(array $data): array
    {
        if (count($data) > self::MAX_LIST) {
            $data = array_slice($data, 0, self::MAX_LIST, true);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::truncate($value);
            } elseif (is_string($value) && strlen($value) > self::MAX_STRING) {
                $data[$key] = substr($value, 0, self::MAX_STRING) . '…[truncated]';
            }
        }

        return $data;
    }
}
