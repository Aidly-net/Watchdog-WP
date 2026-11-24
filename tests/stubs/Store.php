<?php

namespace WebsiteWatchdog\Agent\Local;

use WebsiteWatchdog\Agent\Sanitizer;
use WPState;

class Store
{
    public function install(): bool
    {
        return true;
    }

    public function record_change(array $event, bool $shareable = false): bool
    {
        $event['payload'] = Sanitizer::clean_payload($event['payload']);
        WPState::$local_changes[] = ['event' => $event, 'shareable' => $shareable];
        return true;
    }

    public function capture(array $event): bool
    {
        WPState::$local_incidents[] = $event;
        return true;
    }

    public function cleanup(): void
    {
    }

    public function flush_outbox(): void
    {
    }
}
