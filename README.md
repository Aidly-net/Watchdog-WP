# Website Watchdog

Website Watchdog is a lightweight WordPress monitoring agent for independent
developers and site owners. It captures PHP fatal errors, unhandled
exceptions, browser JavaScript errors, and important WordPress state changes,
groups and correlates incidents locally, and sends minimal notifications to
the Website Watchdog service for alert delivery.

Website Watchdog is an independent software project operated by Aidly.net.

## Features

- Captures PHP fatal errors and uncaught exceptions.
- Reports browser JavaScript errors through a WordPress REST endpoint.
- Tracks plugin, theme, and WordPress update-related state changes.
- Removes sensitive field values and URL query strings before transmission.
- Queues events locally when the service cannot be reached.
- Limits the offline queue to 50 events or 256 KB, discarding the oldest events first.
- Caps outgoing state events at 32 KB; incidents are grouped locally.
- Limits incident source, URL and metadata to 2,002 JSON bytes each, and stack traces to 8,192 JSON bytes. Long traces retain the first 10 and last 5 frames. Incident messages use the remaining event budget.
- Browser reports that exceed the 16 KiB endpoint limit are trimmed before sending, preserving the first 10 and last 5 stack entries when available.
- Uses a 2-second delivery timeout and an exponential backoff (30 s up to 1 h) after failed deliveries, so an outage does not slow requests; queued events are retried by cron in batches of 10.
- Provides an administrator settings page, test event forms, and incident history.
- Retains incidents for 30 days after last seen and changes for 30 days; removes local data on uninstall.

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

Incident messages, sources, URLs, traces and custom event details stay in
WordPress. New or reopened incidents send a generic notification, an
administrator-only WordPress link, severity, and up to 20 recent plugin/theme changes
(names, actions, timestamps and available versions). SaaS also receives
opaque IDs, revisions and status needed for synchronization. Plugin/theme
installations and core updates continue to be sent in the separate state-change
feed, along with WordPress/PHP versions in heartbeats. Plugin and theme names
and versions may reveal installed software.

WordPress remains authoritative for status. Portal actions require the site's
public HTTPS REST endpoint and a trusted certificate. WordPress-to-SaaS
updates use WP-Cron, which can be delayed; an expired local incident link
reports that the incident is unavailable. Existing historical SaaS incidents
are not deleted. The administrator link must have the same origin as the
registered site URL. Legacy incident endpoints are disabled.

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
        'plan' => 'starter',
        'source' => 'checkout',
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

- `$action`: A non-empty event identifier.
- `$details`: An associative array stored locally. Nested arrays and scalar
  values are supported; sensitive field names are redacted. The `truncated`
  key is reserved.
- `$target`: A required non-empty system, feature, or component.

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

Both functions return `true` when the event is stored locally, including when
no API key is configured; `false` indicates invalid input or storage failure.
They are available after the plugin has loaded, so call them from
WordPress hooks or later application code rather than before plugin loading.

## License

Website Watchdog is licensed under the
[GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).