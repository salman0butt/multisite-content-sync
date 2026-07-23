# Architecture

Multisite Content Sync follows a layered, dependency-inverted architecture without introducing a framework-specific service container.

## Layers

- **Domain** contains immutable content mappings, queue jobs, hashes, statuses, and synchronization exceptions.
- **Application** contains connection, payload, receiver, queue, retry, and taxonomy use cases.
- **Contracts** define persistence, encryption, HTTP, logging, and WordPress hook boundaries.
- **Infrastructure** adapts `wpdb`, OpenSSL, the WordPress HTTP API, media APIs, WP-Cron, and WordPress hooks.
- **REST/Admin** translate authenticated requests into application use cases.
- **Plugin** is the composition root and wires concrete dependencies.

Dependencies point inward. REST controllers never issue SQL, application services do not know REST response formats, and persistence implementations are replaceable through contracts.

## Synchronization flow

```text
Post save or manual request
        |
        v
One durable job per destination
        |
        v
Atomic queue claim and payload build
        |
        v
Authenticated destination receiver
        |
        +--> Safe media import and URL/ID rewriting
        +--> Category and tag resolution
        +--> Idempotent post/page create or update
        +--> Conflict hash check
        |
        v
Source mapping, logs, retry, or conflict state
```

## Idempotency and conflicts

The destination identifies content with source site UUID, source object type, and source object ID. Repeated requests update the same destination object. The receiver stores both the last source hash and the last destination-state hash. A local destination edit changes the destination hash and causes a normal request to return HTTP `409`; only an explicit authorized force request bypasses that guard.

Queue jobs have a deterministic active key. A unique database index prevents multiple active jobs for the same connection, source object, and operation. Atomic conditional updates ensure two workers cannot claim the same job.

## Reliability

- Remote work never runs inside the post-save request.
- WP-Cron processes bounded batches.
- Processing locks expire after a stale interval.
- Transient transport, rate-limit, timeout, and server errors use capped exponential backoff.
- Permanent validation failures stop immediately.
- Receiver updates snapshot the previous post, taxonomy, thumbnail, and protected mapping metadata before mutation.
- Newly imported media is removed if a receiver operation rolls back.
- Structured logs redact credential-like keys.

## Security

- Destination authentication uses dedicated WordPress Application Passwords.
- Credentials use versioned AES-256-GCM envelopes and remain backward compatible with version 0.1 ciphertext.
- All REST routes have explicit capability callbacks and schemas.
- Remote API calls and streamed media downloads use WordPress safe HTTP functions.
- Media is HTTPS-only, size-limited, and verified against allowed image MIME types.
- Incoming receiver writes are guarded so they cannot trigger outgoing synchronization loops.
- Public REST errors do not expose encryption, database, or stack details.

## Database

Schema version 2 contains:

- `mcs_connections`
- `mcs_rules`
- `mcs_mappings`
- `mcs_jobs`
- `mcs_logs`

`dbDelta()` applies upgrades, and startup checks the stored schema version so upgrades are not limited to plugin reactivation.
