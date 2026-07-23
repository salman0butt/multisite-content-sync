# Architecture

Multisite Content Sync follows a layered, dependency-inverted architecture without introducing a heavy framework.

## Layers

- **Domain** contains immutable business objects and enums.
- **Application** contains use cases that coordinate domain behavior through contracts.
- **Contracts** define persistence, encryption, HTTP, and hook boundaries.
- **Infrastructure** adapts WordPress APIs, `wpdb`, OpenSSL, and remote HTTP.
- **REST/Admin** expose delivery mechanisms and contain no persistence logic.
- **Plugin** is the composition root and the only place that wires concrete dependencies.

## Design principles

- Dependencies point inward toward contracts and domain objects.
- Application Passwords are encrypted at rest using AES-256-GCM and WordPress salts.
- Credentials are never serialized by REST responses.
- Remote requests use `wp_safe_remote_get()` to reduce SSRF risk.
- Every REST route has an explicit capability check and argument schema.
- Database changes are versioned and installed with `dbDelta()`.
- Content synchronization will run through durable jobs instead of blocking post saves.

## Current vertical slice

Version 0.1.0 implements connection creation, storage, listing, deletion, and an authenticated receiver handshake. The next slice adds normalized post/page payloads, mappings, queue processing, and idempotent upserts.
