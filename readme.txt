=== Multisite Content Sync ===
Contributors: salman0butt
Tags: multisite, content, synchronization, rest-api
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Securely synchronize WordPress content across independent sites.

== Description ==

Multisite Content Sync is being built as a secure hub-and-spoke content synchronization plugin. Version 0.1.0 provides the plugin foundation, encrypted remote credentials, destination connection management, a receiver capability, a handshake API, database migrations, and automated quality checks.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress Plugins screen.
3. Install and activate the plugin on both source and destination sites.
4. Create a dedicated destination user with the Content Sync Receiver role.
5. Generate a WordPress Application Password for that user.

== Changelog ==

= 0.1.0 =
* Initial architecture and secure connection foundation.
