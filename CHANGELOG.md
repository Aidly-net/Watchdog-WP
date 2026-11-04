## v1.0.0
- Captures PHP fatal errors and uncaught exceptions, and reports browser JavaScript errors.
- Monitors plugin, theme, and WordPress state changes, including installation, activation, updates, and deactivation events.
- Sends sanitized incident and event telemetry to the Website Watchdog service.
- Queues events when the service is unavailable, with a limit of 50 queued events.
- Provides an admin settings page, incident history, and test forms.
- Exposes `website_watchdog_capture_event()` and `website_watchdog_capture_incident()` for custom integrations.
- Removes plugin options, cached data, queued events, and scheduled hooks on uninstall.

## v1.1.0
- Backoff: a new DeliveryState.php records transport errors, 429s and 5xx responses. It backs off for 30 s, doubling up to 1 h. While backing off, events go straight to the queue with no network call, so an outage costs one short request per window.
- Delivery timeout: sends in Client.php are now blocking with a 2 s timeout and no redirects. Key verification uses 3 s instead of 10 s. Before, sends were non-blocking, so outages were never detected and those events were silently lost.
- Rejected payloads: a 4xx response (other than 401 and 429) drops the event instead of retrying forever. A 401 marks the key unverified.
- Dedupe: a new EventDeduper.php sends an identical incident once per 60 seconds, so a hot loop no longer uses up the rate limit.
- Payload limit: a new PayloadLimiter.php truncates strings over 2,000 characters and lists over 50 items, and drops anything still over 32 KB.
- Queue: Queue.php is now capped at 256 KB as well as 50 items. The cron flush skips the network during backoff, sends at most 10 events per run, and stops at the first failure.

### Community contributors
- Special thanks to [PeepSoWP](https://www.reddit.com/user/PeepSoWP/) for valuable feedback and suggestions that helped improve this release.

## Requirements
- WordPress 6.5 or newer (tested up to 7.1)
- PHP 7.4 or newer (tested up to 8.5)
- A Website Watchdog API key

