<?php

namespace WebsiteWatchdog\Agent\Http;

class Queue
{
    private const TRANSIENT_KEY = 'website_watchdog_offline_queue';
    private const MAX_ITEMS = 50;
    private const MAX_BYTES = 262144;
    private const FLUSH_BATCH = 10;

    public function add(array $payload): void
    {
        $queue = get_option(self::TRANSIENT_KEY, []);
        $queue = is_array($queue) ? $queue : [];
        $queue[] = $payload;
        $queue = $this->enforce_limits($queue);

        if (empty($queue)) {
            delete_option(self::TRANSIENT_KEY);
            return;
        }

        update_option(self::TRANSIENT_KEY, $queue, false);
    }

    public function flush(): array
    {
        $queue = get_option(self::TRANSIENT_KEY, []);
        $queue = is_array($queue) ? $queue : [];
        $queue = $this->enforce_limits($queue);

        if (empty($queue)) {
            delete_option(self::TRANSIENT_KEY);
            return [];
        }

        update_option(self::TRANSIENT_KEY, $queue, false);

        $state = new DeliveryState();
        if ($state->is_backing_off()) {
            return $queue;
        }

        $client = new Client();

        // Verify once before retrying; keep the queue untouched if the key is invalid or the server is down.
        $verified = $client->verify();
        if (true !== $verified) {
            if (null === $verified) {
                $state->record_failure();
            }

            return $queue;
        }

        $remaining = [];
        $sent = 0;
        $stopped = false;

        foreach ($queue as $item) {
            if (!is_array($item)) {
                continue;
            }

            // Bounded batch; stop at the first failed delivery and keep the rest in order.
            if ($stopped || $sent >= self::FLUSH_BATCH) {
                $remaining[] = $item;
                continue;
            }

            $result = $client->deliver($item);
            $sent++;

            if (Client::RESULT_OK === $result || Client::RESULT_DROP === $result) {
                continue;
            }

            $stopped = true;
            $remaining[] = $item;
        }

        if (empty($remaining)) {
            delete_option(self::TRANSIENT_KEY);
        } else {
            update_option(self::TRANSIENT_KEY, $remaining, false);
        }

        return $remaining;
    }

    private function enforce_limits(array $queue): array
    {
        while (count($queue) > self::MAX_ITEMS) {
            array_shift($queue);
        }

        while (count($queue) > 1 && $this->size($queue) > self::MAX_BYTES) {
            array_shift($queue);
        }

        return $queue;
    }

    private function size(array $queue): int
    {
        $json = wp_json_encode($queue);

        return false === $json ? 0 : strlen($json);
    }
}
