# Website Watchdog

Website Watchdog is a lightweight WordPress monitoring agent for independent
developers and site owners. It captures PHP fatal errors, unhandled
exceptions, browser JavaScript errors, and important WordPress state changes,
groups and correlates incidents locally, and sends minimal alerts either by
WordPress e-mail or through Website Watchdog Cloud.

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
- In Cloud mode, attempts incident delivery immediately after saving with a 2-second HTTP timeout; a local outbox and WP-Cron retry failed updates in batches of 10.
- Sends minimal incident e-mails through `wp_mail()` in E-mail mode, or uses Website Watchdog Cloud for Slack/Webhook notifications.
- Uses exponential backoff (30 s up to 1 h) for failed state-event deliveries.
- Provides an administrator settings page, test event forms, and incident history.
- Retains incidents for 30 days after last seen and changes for 30 days; removes local data on uninstall.

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer
- An API key from the Website Watchdog portal when using Slack/Webhook mode

E-mail mode works locally with WordPress's configured mail delivery. Slack/Webhook
notifications, remote uptime monitoring, and Cloud features require a Website
Watchdog API key.

## Installation

1. Download or clone this repository.
2. Copy the `Watchdog-WP` directory to `wp-content/plugins/`.
3. Activate **Website Watchdog** from the WordPress Plugins screen.
4. Open **Website Watchdog > Settings**.
5. In **Notification**, keep E-mail selected and enter a recipient address, or select Slack/Webhook and enter an API key from <https://wd.aidly.net/>.
6. Save the settings and use the test forms to verify local capture.

The public service pages are available at:

- Website: <https://wd.aidly.net/>
- Terms: <https://wd.aidly.net/terms>
- Privacy: <https://wd.aidly.net/privacy>

## Data and privacy

Incident messages, sources, URLs, traces and custom event details stay in
WordPress. In E-mail mode, new or reopened incidents send a generic message,
severity, and a local administrator link through WordPress mail. In
Slack/Webhook mode, the Cloud service receives opaque IDs, revisions, status,
severity, the local link, and up to 20 approved plugin/theme changes for
notification and synchronization. Site-change events and heartbeats are sent
only in Slack/Webhook mode. Plugin and theme names and versions may reveal
installed software. Optional anonymous version reports are sent only after
consent and are independent of the notification method.

WordPress remains authoritative for status. Portal actions require the site's
public HTTPS REST endpoint and a trusted certificate. Cloud incident captures
attempt delivery immediately when the API key is verified. Failed Cloud
delivery and other outbound status updates use WP-Cron, which can be delayed.
WordPress mail failures are logged and are not automatically retried. SaaS
event records are retained for 12 months after occurrence and incident records
for 12 months after last seen; a local incident link can become unavailable
after the WordPress record expires. The administrator link must have the same
origin as the registered site URL. Legacy incident endpoints are disabled.

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