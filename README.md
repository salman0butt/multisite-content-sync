# Multisite Content Sync

A secure hub-and-spoke WordPress plugin for synchronizing posts and pages from one source site to multiple independent destination sites.

## Current status

Version `0.2.0` implements the complete backend MVP:

- secure destination connections using WordPress Application Passwords
- encrypted credentials with versioned AES-256-GCM envelopes
- authenticated receiver handshake and content endpoints
- idempotent post and page upserts
- category and tag synchronization
- featured-image and Gutenberg media synchronization
- source-to-destination mappings
- destination conflict detection with explicit force overwrite
- durable background jobs, atomic claiming, retries, cancellation, and stale-lock recovery
- sanitized structured logs
- PHP 8.3 minimum with CI on PHP 8.3, 8.4, and 8.5
- WordPress Core and Extra coding standards, PHPStan level 8, and PHPUnit

The administration experience is intentionally API-first for this release. A React dashboard and Gutenberg document panel remain future UI work.

## Requirements

- WordPress 7.0+
- PHP 8.3+
- OpenSSL
- HTTPS on source and destination sites (including synchronized media URLs)
- WordPress Application Passwords enabled on destination sites

Install the plugin on both source and destination sites. Create a dedicated destination user with the **Content Sync Receiver** role, then generate an Application Password for that user.

## Architecture

```text
Domain <- Application <- REST/Admin
   ^           ^
   |           |
Contracts <- Infrastructure
```

The plugin uses immutable domain objects, dependency inversion, small application services, and WordPress adapters. Remote work never runs inside a normal post-save request.

See [docs/architecture.md](docs/architecture.md).

## Development

```bash
composer install
composer check
```

The full check runs syntax validation, WordPress coding standards, PHPStan level 8, and PHPUnit.

## REST API

Administrator routes require `manage_options`:

```text
GET    /wp-json/mcs/v1/connections
POST   /wp-json/mcs/v1/connections
DELETE /wp-json/mcs/v1/connections/{id}
POST   /wp-json/mcs/v1/connections/{id}/test

POST   /wp-json/mcs/v1/sync
GET    /wp-json/mcs/v1/jobs
POST   /wp-json/mcs/v1/jobs/{id}/retry
DELETE /wp-json/mcs/v1/jobs/{id}
```

The destination receiver user requires `mcs_receive_content`:

```text
GET  /wp-json/mcs/v1/receiver/handshake
POST /wp-json/mcs/v1/receiver/content
```

### Queue a post or page

```json
{
  "post_id": 123,
  "connection_ids": [1, 2],
  "force": false
}
```

Omitting `connection_ids` queues all connections currently marked connected.

### Automatic synchronization

Automatic synchronization is opt-in. Return connection IDs through the `mcs_auto_sync_connection_ids` filter. The save hook only enqueues work; it never performs remote HTTP calls.

## Conflict behavior

The destination stores a hash of the synchronized destination state. If someone edits the destination locally, the next normal sync returns HTTP `409` and records a conflict. An authorized manual request with `"force": true` can overwrite it.

## WP-Cron

The queue checks every minute through WP-Cron. Production sites should invoke `wp-cron.php` from a real system scheduler for predictable processing.

## Security

- credentials are encrypted at rest and never serialized
- all receiver and administrator routes have capability checks
- remote requests and media downloads use WordPress safe HTTP APIs
- media imports require HTTPS, validate MIME type, and enforce a 10 MB limit
- logs redact passwords, authorization values, credentials, secrets, and tokens
- incoming saves cannot create outgoing synchronization loops

Report vulnerabilities privately rather than opening a public issue.

## License

GPL-2.0-or-later.
