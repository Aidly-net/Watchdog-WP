<?php

namespace WebsiteWatchdog\Agent\Http;

class PayloadLimiter
{
    public const MAX_BYTES = 32768;
    private const MAX_STRING = 2000;
    private const MAX_LIST = 50;
    private const MAX_EVENT_FIELD_LENGTH = 255;

    /** Truncates oversized strings and lists; returns null when the payload still exceeds MAX_BYTES. */
    public static function limit(array $payload): ?array
    {
        if ('state_change' === ($payload['event_type'] ?? null) && isset($payload['payload']) && is_array($payload['payload'])) {
            foreach (['action', 'target'] as $field) {
                if (isset($payload['payload'][$field])) {
                    if (!is_string($payload['payload'][$field]) || !preg_match('/^.{0,' . self::MAX_EVENT_FIELD_LENGTH . '}/us', $payload['payload'][$field], $match)) {
                        return null;
                    }
                    $payload['payload'][$field] = $match[0];
                }
            }
            if (isset($payload['payload']['details']) && is_array($payload['payload']['details'])) {
                return self::limit_details($payload, true);
            }
        } elseif ('incident' === ($payload['event_type'] ?? null)
            && isset($payload['payload']['details']) && is_string($payload['payload']['details'])) {
            return self::limit_details($payload, true);
        } elseif (isset($payload['severity'], $payload['source'], $payload['details'])
            && is_string($payload['details'])) {
            $payload['source'] = self::limit_string((string) $payload['source'], self::MAX_STRING + 2);
            return self::limit_details($payload, false);
        }

        $payload = self::truncate($payload);
        $json = wp_json_encode($payload);

        return false !== $json && strlen($json) <= self::MAX_BYTES ? $payload : null;
    }

    private static function limit_details(array $payload, bool $nested): ?array
    {
        if ($nested) {
            $details = $payload['payload']['details'];
            unset($payload['payload']['details']);
        } else {
            $details = $payload['details'];
            unset($payload['details']);
        }
        $payload = self::truncate($payload);
        if ($nested) {
            $context = &$payload['payload'];
        } else {
            $context = &$payload;
        }
        $context['details'] = is_array($details) ? (object) $details : $details;
        $json = wp_json_encode($payload);
        if (false === $json) {
            return null;
        }
        if (strlen($json) <= self::MAX_BYTES) {
            return $payload;
        }

        if (is_string($details)) {
            $context['details'] = '';
            $base = wp_json_encode($payload);
            if (false === $base) {
                return null;
            }
            $budget = self::MAX_BYTES - strlen($base) + 2;
            if ($budget < strlen(wp_json_encode('...[truncated]'))) {
                return null;
            }
            $context['details'] = self::limit_string($details, $budget);
            $json = wp_json_encode($payload);

            return false !== $json && strlen($json) <= self::MAX_BYTES ? $payload : null;
        }

        $kept = [];
        foreach ($details as $key => $value) {
            $candidate = $kept;
            $candidate[$key] = $value;
            $candidate['truncated'] = true;
            $context['details'] = (object) $candidate;
            $json = wp_json_encode($payload);
            if (false === $json) {
                return null;
            }
            if (strlen($json) > self::MAX_BYTES) {
                break;
            }
            $kept[$key] = $value;
        }

        $kept['truncated'] = true;
        $context['details'] = (object) $kept;
        $json = wp_json_encode($payload);

        return false !== $json && strlen($json) <= self::MAX_BYTES ? $payload : null;
    }

    public static function limit_string(string $value, int $max_json_bytes): string
    {
        $encoded = wp_json_encode($value);
        if (false === $encoded || strlen($encoded) <= $max_json_bytes) {
            return $value;
        }

        $value = json_decode($encoded, true);
        $suffix = '...[truncated]';
        $low = 0;
        $high = min(strlen($value), $max_json_bytes);
        while ($low < $high) {
            $length = intdiv($low + $high + 1, 2);
            preg_match('/^.{0,' . $length . '}/us', $value, $match);
            $candidate = $match[0] . $suffix;
            if (strlen(wp_json_encode($candidate)) <= $max_json_bytes) {
                $low = $length;
            } else {
                $high = $length - 1;
            }
        }

        preg_match('/^.{0,' . $low . '}/us', $value, $match);

        return $match[0] . $suffix;
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
