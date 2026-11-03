<?php

namespace WebsiteWatchdog\Agent\Http;

/**
 * Tracks consecutive delivery failures and enforces an exponential backoff so a backend outage
 * costs at most one short request per backoff window instead of one per captured event.
 */
class DeliveryState
{
    private const BACKOFF_KEY = 'website_watchdog_backoff';
    private const FAILURES_KEY = 'website_watchdog_delivery_failures';
    private const BASE_DELAY = 30;
    private const MAX_DELAY = 3600;
    private const FAILURE_MEMORY = 7200;

    public function is_backing_off(): bool
    {
        return !empty(get_transient(self::BACKOFF_KEY));
    }

    public function record_failure(): void
    {
        $failures = (int) get_transient(self::FAILURES_KEY) + 1;
        $delay = min(self::MAX_DELAY, self::BASE_DELAY * (2 ** min($failures - 1, 10)));

        set_transient(self::FAILURES_KEY, $failures, self::FAILURE_MEMORY);
        set_transient(self::BACKOFF_KEY, true, $delay);
    }

    public function record_success(): void
    {
        delete_transient(self::BACKOFF_KEY);
        delete_transient(self::FAILURES_KEY);
    }
}
