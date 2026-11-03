# Website Watchdog

Website Watchdog is a lightweight WordPress monitoring agent for independent
developers and site owners. It captures PHP fatal errors, unhandled
exceptions, browser JavaScript errors, and important WordPress state changes,
then sends sanitized telemetry to the Website Watchdog service for incident
grouping and decision-support alerts.

Website Watchdog is an independent software project operated by Aidly.net.

## Features

- Captures PHP fatal errors and uncaught exceptions.
- Reports browser JavaScript errors through a WordPress REST endpoint.
- Tracks plugin, theme, and WordPress update-related state changes.
- Removes sensitive field values and URL query strings before transmission.
- Queues events locally when the service cannot be reached.
- Limits the offline queue to 50 events or 256 KB, discarding the oldest events first.
- Caps each event at 32 KB (long strings and lists are truncated) and sends identical incidents at most once per minute.
- Uses a 2-second delivery timeout and an exponential backoff (30 s up to 1 h) after failed deliveries, so an outage does not slow requests; queued events are retried by cron in batches of 10.
- Provides an administrator settings page, test event forms, and incident history.
- Removes plugin options, cached data, queued events, and scheduled hooks on uninstall.

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer
- An API key from the Website Watchdog portal

The plugin requires an external Website Watchdog service for telemetry
processing and alert delivery. It does not send alerts directly to Slack.

## Installation

1. Download or clone this repository.
2. Copy the `Watchdog-WP` directory to `wp-content/plugins/`.
3. Activate **Website Watchdog** from the WordPress Plugins screen.
4. Open **Website Watchdog > Settings**.
5. Paste the API key generated in the Website Watchdog portal <https://wd.aidly.net/>.
6. Save the settings and use the test forms to verify delivery.

The public service pages are available at:

- Website: <https://wd.aidly.net/>
- Terms: <https://wd.aidly.net/terms>
- Privacy: <https://wd.aidly.net/privacy>

## Data and privacy

The agent transmits error messages, file names, line numbers, sanitized stack
traces, WordPress/PHP version information, plugin and theme state changes, and
URLs associated with captured errors. Query strings are removed from URLs
locally before transmission.

Passwords, authentication tokens, API keys, secrets, raw POST data, and other
values whose field names appear sensitive are redacted locally where detected.
Error messages and custom event details can still contain sensitive content if
the originating site puts it there, so review your site’s data before enabling
telemetry.

See the [Privacy Policy](https://wd.aidly.net/privacy) for more information.

## Custom events and alerts

The plugin exposes two helper functions for integrations that need to report
application-specific activity.

### Send a custom event

Use `website_watchdog_capture_event()` for a state change or other noteworthy event:

```php
website_watchdog_capture_event(
    'subscription_started',
    [
        'details' => [
            'plan' => 'starter',
            'source' => 'checkout',
        ],
    ],
    'customer-account'
);
```

The function signature is:

```php
website_watchdog_capture_event(
    string $action,
    array $details = [],
    ?string $target = null
): bool;
```

- `$action`: A short identifier for the event.
- `$details`: Additional event data. Sensitive values are sanitized before
  transmission.
- `$target`: An optional system, feature, or component associated with the
  event.

### Send a custom alert

Use `website_watchdog_capture_incident()` when you want to send a custom alert or
incident:

```php
website_watchdog_capture_incident(
    'Payment provider is unavailable.',
    'critical',
    'my-plugin||my-script',
);
```

The function signature is:

```php
website_watchdog_capture_incident(
    string $details,
    string $severity = 'error',
    ?string $source = null
): bool;
```

- `$details`: The alert message.
- `$severity`: `info`, `warning`, `error`, or `critical`.
- `$source`: An optional plugin, component, or integration identifier.

Both functions return `true` when the event is accepted for delivery and
`false` when the API key is unavailable, unverified, rate-limited, or delivery
fails. They are available after the plugin has loaded, so call them from
WordPress hooks or later application code rather than before plugin loading.

## License

Website Watchdog is licensed under the
[GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).