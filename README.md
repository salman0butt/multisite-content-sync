# Multisite Content Sync

A secure, maintainable WordPress plugin for synchronizing content from one source site to multiple independent destination sites.

## Status

The first production-oriented foundation is implemented:

- PHP 8.3 minimum with CI coverage for PHP 8.3, 8.4, and 8.5
- WordPress 7.0 minimum
- strict types, namespaces, immutable domain objects, enums, and constructor injection
- versioned database schema for connections, rules, mappings, jobs, and logs
- encrypted Application Password storage using AES-256-GCM
- least-privilege receiver capability and dedicated receiver role
- authenticated receiver handshake endpoint
- connection create/list/delete/test REST endpoints
- WordPress Coding Standards, PHPStan level 8, and PHPUnit configuration

## Architecture

The project uses a lightweight layered architecture:

```text
Domain <- Application <- Delivery (REST/Admin)
   ^           ^
   |           |
Contracts <- Infrastructure (wpdb, HTTP, encryption)
```

The `Plugin` class is the composition root. Business use cases depend on interfaces rather than WordPress infrastructure, which keeps the code testable and prevents controllers from becoming large service objects.

See [docs/architecture.md](docs/architecture.md).

## Requirements

- WordPress 7.0+
- PHP 8.3+
- OpenSSL PHP extension
- HTTPS on every connected destination

WordPress Application Passwords must be used for remote authentication. Never use an administrator's main account password.

## Development

```bash
composer install
composer check
```

Useful commands:

```bash
composer lint
composer cs
composer cs:fix
composer analyse
composer test
```

## REST API

Administrator routes require `manage_options`:

```text
GET    /wp-json/mcs/v1/connections
POST   /wp-json/mcs/v1/connections
DELETE /wp-json/mcs/v1/connections/{id}
POST   /wp-json/mcs/v1/connections/{id}/test
```

Create payload:

```json
{
  "name": "UK website",
  "site_url": "https://uk.example.com",
  "username": "mcs-receiver",
  "application_password": "xxxx xxxx xxxx xxxx xxxx xxxx"
}
```

The receiver route requires a user with `mcs_receive_content`:

```text
GET /wp-json/mcs/v1/receiver/handshake
```

## Roadmap

1. Idempotent post and page upserts
2. Durable queue processing and retry policy
3. Source-to-destination content mappings
4. Category, tag, featured image, and inline media synchronization
5. Conflict detection through source and destination hashes
6. React administration interface using WordPress components
7. Gutenberg document panel and manual sync controls

## Security

Please report vulnerabilities privately rather than opening a public issue. Credentials, authorization headers, and decrypted secrets must never be written to logs.

## License

GPL-2.0-or-later.
