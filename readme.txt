=== Website Watchdog - PHP Error & Incident Alerts ===
Contributors: aidly
Tags: error log, slack notifications, debugging, monitoring, fatal error
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight watchdog agent that captures PHP fatal errors, WordPress state changes and frontend JavaScript exceptions, sending decision-support alerts to Slack.

== Description ==

Website Watchdog is a WordPress monitoring agent that sends errors and site changes to the Website Watchdog service. The service groups incidents, associates them with recent changes when possible, and can send alerts to Slack, email, or an HTTPS webhook.

= Features =

* Captures PHP fatal errors, uncaught exceptions, and browser JavaScript errors.
* Tracks plugin and theme installations and updates, plugin activations and deactivations, theme switches, and WordPress core updates.
* Redacts values in sensitive fields and removes URL query strings before transmission.
* Queues events when delivery fails and retries them using WP-Cron. The queue is limited to 50 events or 256 KB; older events may be discarded.
* Sends scheduled heartbeats and provides a health endpoint for availability checks.
* Provides administrator settings, test event forms, and incident history.
* Supports custom events and incidents through PHP helper functions.

**Website Watchdog is an independent software project operated by Aidly.net.**

= Third-Party Service Disclosure =

This plugin is a client agent that relies on an external SaaS API to process incident telemetry, associate incidents with recent site changes, group repeated errors, and dispatch configured alerts.

* **Service Name:** Website Watchdog API
* **Service URL:** https://wd.aidly.net/
* **Terms of Service:** https://wd.aidly.net/terms
* **Privacy Policy:** https://wd.aidly.net/privacy

**What Data Is Collected and Transmitted:**
To deliver accurate incident alerts and root-cause analysis, the plugin transmits the following data to `https://wd.aidly.net/api` when triggered:
* **Error Telemetry:** PHP error messages, file names, line numbers, and sanitized stack traces.
* **Site Context:** PHP and WordPress versions and information about plugins or themes involved in reported changes.
* **System Events:** WordPress hooks indicating plugin, theme, or core updates and activations.
* **Request Metadata:** URLs included in error telemetry, with query strings removed locally before transmission.

*Note: Values in fields whose names appear sensitive are redacted locally, and URL query strings are removed. Error messages and custom event details can still contain sensitive content, so review your site's data before enabling telemetry.*

When the plugin is uninstalled, its settings, offline queue, cached incident transients, and scheduled events are removed.

== Installation ==

1. Upload the `website-watchdog` folder to the `/wp-content/plugins/` directory (or install directly via the WordPress Plugin Directory).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Website Watchdog > Settings** and enter the API key generated in the Website Watchdog portal at https://wd.aidly.net/settings/api-keys.