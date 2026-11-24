=== Website Watchdog - PHP Error & Incident Alerts ===
Contributors: aidly
Tags: error log, slack notifications, debugging, monitoring, fatal error
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight watchdog agent that captures PHP fatal errors, WordPress state changes and frontend JavaScript exceptions, sending decision-support alerts to Slack.

== Description ==

Website Watchdog captures, groups and correlates incidents locally in WordPress. The service sends generic alerts with a link back to WordPress and approved recent changes.

= Features =

* Captures PHP fatal errors, uncaught exceptions, and browser JavaScript errors.
* Tracks plugin and theme installations and updates, plugin activations and deactivations, theme switches, and WordPress core updates.
* Redacts values in sensitive fields and removes URL query strings before transmission.
* Queues events when delivery fails and retries them using WP-Cron. The queue is limited to 50 events or 256 KB; older events may be discarded.
* Sends scheduled heartbeats and provides a health endpoint for availability checks.
* Provides administrator settings, test event forms, and local incident history.
* Retains incidents for 30 days after last seen and local changes for 30 days.
* Supports custom events and incidents through PHP helper functions.

**Website Watchdog is an independent software project operated by Aidly.net.**

= Third-Party Service Disclosure =

This plugin relies on an external SaaS API to record site changes and dispatch configured alerts.

* **Service Name:** Website Watchdog API
* **Service URL:** https://wd.aidly.net/
* **Terms of Service:** https://wd.aidly.net/terms
* **Privacy Policy:** https://wd.aidly.net/privacy

**What Data Is Collected and Transmitted:**
The plugin transmits the following data to `https://wd.aidly.net/api` when triggered:
* **Site Context:** PHP and WordPress versions and information about plugins or themes involved in reported changes.
* **System Events:** WordPress hooks indicating plugin, theme, or core updates and activations.
* **Incident notification:** A generic message, administrator incident link, opaque identifiers, revision and status, plus recent plugin/theme changes (names, actions, timestamps and available versions).

Incident diagnostics and custom events remain local. The separate site-change feed and heartbeat still share plugin/theme information and system versions. Existing historical SaaS incident data is not deleted. Status updates from SaaS require a reachable public HTTPS WordPress REST endpoint with a trusted certificate; WP-Cron retries can be delayed.

When the plugin is uninstalled, its local incident and change tables, settings, queues, cached data and scheduled events are removed.

== Installation ==

1. Upload the `website-watchdog` folder to the `/wp-content/plugins/` directory (or install directly via the WordPress Plugin Directory).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Website Watchdog > Settings** and enter the API key generated in the Website Watchdog portal at https://wd.aidly.net/settings/api-keys.