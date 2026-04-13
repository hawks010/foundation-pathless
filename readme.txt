=== Foundation: Pathless ===
Contributors: inkfire
Tags: links, broken links, 404, seo, maintenance, accessibility
Requires at least: 5.5
Tested up to: 6.5
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An asynchronous, self-hosted link checker that finds broken links and accessibility issues without slowing down your site.

== Description ==

Foundation: Pathless is part of the Foundation plugin series by Inkfire Limited — a suite of modular, minimal tools for clean, performant WordPress sites.

Pathless runs in the background, scanning your site's posts and pages for broken links (404s), server errors, and unreachable URLs. It also performs accessibility checks for common issues like generic anchor text. All results are displayed in a clean, actionable dashboard right in your WordPress admin. Because it's a self-hosted solution, you never have to worry about subscriptions or external services.

== Installation ==

1. Upload the `foundation-pathless` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Foundation > Pathless** in the admin menu to view the dashboard and start a scan.

== Changelog ==

= 1.5.3 =
* Synced the dashboard with the canonical shared Foundation admin shell assets.

= 1.5.2 =
* Rebuilt the admin dashboard on the shared Foundation shell while preserving the existing scan, dismiss, and settings flows.

= 1.5.1 =
* Added GitHub release updater support and repository metadata.

= 1.2.0 =
* Complete architectural rebuild with asynchronous background processing.
* New interactive dashboard with one-click actions (Edit, Unlink, Recheck, Dismiss).
* Implemented Accessibility Checker for generic and empty link text.
* Unified admin menu under "Foundation".
