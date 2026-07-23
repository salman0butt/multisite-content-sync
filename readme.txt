=== Multisite Content Sync ===
Contributors: salman0butt
Tags: multisite, content, synchronization, rest-api
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Securely synchronize posts and pages across independent WordPress sites.

== Description ==

Multisite Content Sync connects independent WordPress websites through authenticated REST requests. It supports durable background synchronization, idempotent post and page upserts, categories, tags, featured images, Gutenberg media, mappings, conflict detection, retries, and sanitized logs.

Install it on source and destination sites. Use a dedicated destination user with the Content Sync Receiver role and a WordPress Application Password.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`.
2. Activate the plugin on source and destination sites.
3. Create a dedicated destination user with the Content Sync Receiver role.
4. Generate a WordPress Application Password.
5. Create and test a destination connection.
6. Queue posts or pages through the synchronization REST endpoint.

== Changelog ==

= 0.2.0 =
* Added durable synchronization jobs and retry processing.
* Added idempotent post and page receiver upserts.
* Added category, tag, featured-image, and Gutenberg media synchronization.
* Added mappings, conflict detection, force overwrite, and loop prevention.
* Hardened credential encryption, deletion, REST errors, multisite activation, and uninstall cleanup.

= 0.1.0 =
* Initial architecture and secure connection foundation.
