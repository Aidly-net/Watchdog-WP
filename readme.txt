=== Website Watchdog - PHP Error & Incident Alerts ===
Contributors: aidly
Tags: error log, slack notifications, debugging, monitoring, fatal error
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight watchdog agent that captures PHP fatal errors and WordPress state changes, sending decision-support alerts to Slack.

== Description ==

Website Watchdog monitors your WordPress site for PHP fatal errors, unhandled exceptions, and critical state changes (like plugin updates), correlating them into real-time decision-support alerts delivered straight to Slack.

**Website Watchdog is an independent software project operated by Aidly.net.**

= Third-Party Service Disclosure =

This plugin is a client agent that relies on an external SaaS API to process incident telemetry, correlate root causes, deduplicate error storms, and dispatch alerts to external channels.

* **Service Name:** Website Watchdog API
* **Service URL:** https://wd.aidly.net/
* **Terms of Service:** https://wd.aidly.net/terms
* **Privacy Policy:** https://wd.aidly.net/privacy

**What Data Is Collected and Transmitted:**
To deliver accurate incident alerts and root-cause analysis, the plugin transmits the following data to `https://wd.aidly.net/api` when triggered:
* **Error Telemetry:** PHP error messages, file names, line numbers, and sanitized stack traces.
* **Site Context:** Active PHP version, WordPress core version, and active plugin/theme list.
* **System Events:** WordPress hooks indicating plugin, theme, or core updates and activations.
* **Request Metadata:** URLs included in error telemetry, with query strings removed locally before transmission.

*Note: Sensitive data (passwords, authentication tokens, API keys, credit card numbers, raw POST data, and URL query parameters) is scrubbed locally on your server before any payload is transmitted to our API. Offline events are capped at 50 items; oldest events are discarded when necessary.*

When the plugin is uninstalled, its settings, offline queue, cached incident transients, and scheduled events are removed.

== Installation ==

1. Upload the `website-watchdog` folder to the `/wp-content/plugins/` directory (or install directly via the WordPress Plugin Directory).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to ** Website Watchdog > Settings** and enter your API key generated from https://wd.aidly.net/settings/api-keys