# Post-merge audit

The 0.2.0 hardening pass covers all currently open backend MVP issues.

## Correctness fixes

- Correct deletion results when a connection does not exist.
- Prevent partial job creation when one selected connection is invalid.
- Prevent duplicate active force and non-force jobs.
- Do not cancel jobs already being processed.
- Persist failed and conflict mapping states.
- Roll back post fields, terms, thumbnail, mapping metadata, and new media after receiver failures.
- Remove incomplete attachments when media metadata processing fails.
- Recover missing mapped attachment files by importing them again.
- Detect source images from block IDs, legacy `wp-image-*` classes, `src`, and `srcset`.
- Run schema upgrades during normal plugin startup.
- Support network activation, deactivation, and uninstall cleanup.

## Security fixes

- Keep non-remote internal exceptions out of REST responses.
- Use versioned authenticated encryption while retaining legacy decryption.
- Enforce receiver payload and media limits.
- Use safe remote HTTP functions, streamed downloads, image MIME validation, and HTTPS.
- Redact credential-like logging context.
