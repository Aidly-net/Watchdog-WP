<?php

namespace WebsiteWatchdog\Agent\Http;

/**
 * Drops repeats of the same incident within a short window so a hot loop firing one error
 * thousands of times sends a single event instead of exhausting the rate budget.
 */
class EventDeduper
{
    private const SEEN_KEY = 'website_watchdog_event_seen';
    private const WINDOW = 60;
    private const MAX_SEEN = 100;

    public function is_duplicate(array $event): bool
    {
        if ('incident' !== ($event['event_type'] ?? '')) {
            return false;
        }

        $incident = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $fingerprint = md5(implode('|', [
            (string) ($incident['severity'] ?? ''),
            (string) ($incident['source'] ?? ''),
            (string) ($incident['error_type'] ?? ''),
            (string) ($incident['details'] ?? ''),
        ]));

        $now = time();
        $seen = get_transient(self::SEEN_KEY);
        $seen = is_array($seen) ? $seen : [];
        $seen = array_filter($seen, static function ($timestamp) use ($now): bool {
            return is_int($timestamp) && ($now - $timestamp) < self::WINDOW;
        });

        if (isset($seen[$fingerprint])) {
            return true;
        }

        $seen[$fingerprint] = $now;
        set_transient(self::SEEN_KEY, array_slice($seen, -self::MAX_SEEN, null, true), self::WINDOW);

        return false;
    }
}
