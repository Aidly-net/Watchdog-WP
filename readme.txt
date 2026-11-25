=== Website Watchdog - PHP Error & Incident Alerts ===
Contributors: aidly
Tags: error log, slack notifications, debugging, monitoring, fatal error
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Capture WordPress incidents locally and receive alerts with links to the details on your site.

== Description ==

Website Watchdog monitors PHP fatal errors, uncaught exceptions, browser JavaScript errors, and WordPress state changes. Incidents are captured, grouped, and correlated with recent changes on your WordPress site.

The Website Watchdog service receives minimal incident notifications and can deliver generic alerts through configured channels such as Slack, email, or webhooks. Incident details are viewed in WordPress, not sent to the service.

= Features =

* Captures PHP fatal errors, uncaught exceptions, and browser JavaScript errors locally.
* Records plugin and theme installations and updates, plugin activations and deactivations, theme switches, and WordPress core updates.
* Groups repeated incidents and shows potentially related changes from the preceding 15 minutes.
* Provides administrator-only incident and event history tables and detailed views, including incident status and mute controls.
* Sends minimal notifications for new or reopened incident groups and synchronizes incident status with the service.
* Sends scheduled heartbeats and provides an authenticated health endpoint for availability checks.
* Supports locally stored custom events and incidents through PHP helper functions.
* Retries failed deliveries using WP-Cron and removes local data on uninstall.

**Website Watchdog is an independent software project operated by Aidly.net.**

= Third-Party Service Disclosure =

The plugin uses the external Website Watchdog service to record site changes, monitor availability, and deliver configured alerts. A verified API key is required for delivery.

* **Service Name:** Website Watchdog API
* **Service URL:** https://wd.aidly.net/
* **Terms of Service:** https://wd.aidly.net/terms
* **Privacy Policy:** https://wd.aidly.net/privacy

**Data sent to Website Watchdog:**

* **Connection and heartbeat:** The site URL, API key for authentication, and plugin, PHP, and WordPress versions.
* **Site changes:** Plugin, theme, and core state-change events, including relevant names and details, are sent separately from incident notifications.
* **Incident notifications and status:** Opaque identifiers, revision, status, mute setting, last-seen time, an administrator incident link, and up to 20 approved recent plugin/theme changes (actions, names, timestamps, and available versions). The service uses these to deliver generic alerts and synchronize status.

Incident messages, sources, URLs, stack traces, and custom events remain on the WordPress site. Custom events are not included in the recent changes sent with incident notifications. The plugin redacts values under sensitive field names and removes URL query strings from sanitized data, but this is not a guarantee that all sensitive information is removed from free text or local records.

The plugin also checks GitHub releases for updates. These requests are made to GitHub and disclose the site's server IP address to GitHub. See https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement.

= Storage and delivery =

Incidents are retained locally for 30 days after they were last seen; events and changes are retained for 30 days after they occurred. Cleanup runs through WP-Cron and may be delayed on low-traffic sites. Minimal incident records remain in the service after local expiry; their WordPress links will no longer show the incident.

Incident updates are queued locally and retried through WP-Cron. A separate offline queue for site-change events holds up to 50 events or 256 KB and may discard older events. Delivery and retry timing depend on WP-Cron and a verified API key. In rare cases, an alert may be delivered more than once if delivery succeeds but confirmation is not recorded.

WordPress is authoritative for incident status. Status updates initiated from the service require a publicly reachable HTTPS WordPress REST endpoint with a trusted certificate. The incident administrator link must use the same origin as the registered site URL.

Uninstalling the plugin removes its local incident, event, and outbox tables, settings, queues, cached data, and scheduled events. It does not delete records already held by the service.

== Installation ==

1. Upload the `website-watchdog` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Website Watchdog > Settings** and enter the API key generated in the Website Watchdog portal at https://wd.aidly.net/settings/api-keys.
4. Use **Send Test Data** to record a test event or incident, then inspect **Event History** or **Incident History**. Configure alert channels in the service.

== Frequently Asked Questions ==

= Where can I see incident details? =

Open **Website Watchdog > Incident History** in WordPress. Select an incident to see its details, recent changes, and status controls. Administrators can also open the WordPress link in a service alert.

= Are custom events sent to the service? =

No. Custom events recorded with `website_watchdog_capture_event()` stay local. WordPress-generated site changes are also stored locally but are sent to the service through the separate site-change feed.

= Can I report my own incidents? =

Yes. Use `website_watchdog_capture_incident( $details, $severity, $source )` after the plugin has loaded. Use `website_watchdog_capture_event( $action, $details, $target )` to record a custom event. Both functions return whether the record was stored locally.