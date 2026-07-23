# Multisite Content Sync

A WordPress plugin for securely synchronizing content between multiple independent WordPress websites from one central dashboard.

> **Project status:** Early development / MVP planning

## Overview

Managing the same content across several WordPress websites is repetitive and error-prone. Multisite Content Sync is designed to let site owners select content on one WordPress installation and publish or update it across connected websites without manually copying it.

The plugin is intended for agencies, publishers, franchise networks, multilingual site owners, and businesses that operate multiple independent WordPress installations.

## The Problem

Teams managing multiple WordPress websites often need to:

- Publish the same announcement on several sites.
- Keep product, service, or landing-page content consistent.
- Update shared content without logging into every website.
- Track whether remote publishing succeeded or failed.
- Prevent duplicate or conflicting content updates.

WordPress Multisite can solve some of these problems, but it is not always suitable when the websites use separate databases, hosting providers, domains, or ownership structures.

## Proposed Solution

Multisite Content Sync connects independent WordPress websites through authenticated REST API requests.

A user can:

1. Connect one or more remote WordPress websites.
2. Select a post, page, or supported custom post type.
3. Choose the destination websites.
4. Send the content immediately or schedule synchronization.
5. Review the status of each synchronization attempt.

## Planned Features

### MVP

- Connect independent WordPress websites.
- Secure API-key or application-password authentication.
- Sync posts and pages.
- Sync titles, content, excerpts, featured images, categories, and tags.
- Create or update existing remote content.
- Select one or multiple destination websites.
- Manual synchronization from the WordPress editor.
- Synchronization history and error logs.
- Retry failed synchronization jobs.

### Future Features

- Custom post type support.
- Scheduled and automatic synchronization.
- Two-way synchronization.
- Conflict detection and resolution.
- Selective field synchronization.
- WooCommerce product synchronization.
- Gutenberg block compatibility checks.
- Bulk content synchronization.
- Webhook-based updates.
- WP-CLI commands.
- Role and capability controls.
- Developer hooks and filters.

## Example Use Cases

- An agency publishes a legal notice across all client websites.
- A franchise updates service information across regional websites.
- A publisher distributes an article to several brands.
- A company keeps shared pages consistent across country-specific sites.
- A WooCommerce business synchronizes selected product information between stores.

## How It Will Work

```text
Source WordPress Site
        |
        | Authenticated REST API request
        v
Remote WordPress Site
        |
        | Validate permissions and payload
        v
Create or update content
        |
        v
Return synchronization result
```

Each synchronized item will store a relationship between the source content ID and the corresponding remote content ID. This allows future synchronization requests to update the correct remote item instead of creating duplicates.

## Proposed Architecture

```text
multisite-content-sync/
├── multisite-content-sync.php
├── includes/
│   ├── class-plugin.php
│   ├── class-api-client.php
│   ├── class-rest-controller.php
│   ├── class-sync-manager.php
│   ├── class-connection-manager.php
│   ├── class-logger.php
│   └── class-admin.php
├── admin/
│   ├── views/
│   ├── css/
│   └── js/
├── languages/
├── tests/
├── uninstall.php
├── readme.txt
└── README.md
```

## Security Goals

The plugin should follow WordPress security best practices:

- Validate and sanitize all input.
- Escape all output.
- Use nonces for administrative actions.
- Use WordPress roles and capabilities.
- Encrypt or safely store remote credentials.
- Authenticate every incoming API request.
- Apply permission callbacks to REST API routes.
- Avoid exposing sensitive synchronization logs.
- Use HTTPS for communication between websites.

## Development Principles

- Follow WordPress Coding Standards.
- Use object-oriented PHP with clear separation of concerns.
- Keep the plugin extensible through actions and filters.
- Add automated tests for synchronization logic.
- Handle failures without interrupting normal WordPress publishing.
- Provide clear user-facing error messages and diagnostic logs.
- Maintain backward compatibility where practical.

## Requirements

The initial target requirements are:

- WordPress 6.4 or later
- PHP 8.1 or later
- HTTPS enabled on connected websites
- WordPress REST API enabled

These requirements may change as development progresses.

## Installation

The plugin is not yet ready for production use.

Once the first release is available, installation will follow the standard WordPress plugin process:

1. Download the plugin ZIP file.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload and activate the plugin.
4. Open **Content Sync → Connections**.
5. Add the URL and credentials for each destination website.
6. Test the connection before synchronizing content.

## Development Setup

```bash
# Clone the repository
git clone https://github.com/salman0butt/multisite-content-sync.git

# Enter the project directory
cd multisite-content-sync
```

Additional Composer, npm, testing, and WordPress environment instructions will be added when the initial plugin structure is implemented.

## Roadmap

- [x] Define the project problem and initial scope.
- [x] Create the GitHub repository.
- [ ] Create the base WordPress plugin structure.
- [ ] Add the connections administration screen.
- [ ] Implement authentication between websites.
- [ ] Add REST API endpoints.
- [ ] Implement post and page synchronization.
- [ ] Add media synchronization.
- [ ] Add logs, retries, and failure reporting.
- [ ] Add automated tests.
- [ ] Publish the first alpha release.

## Contributing

The project is currently in its initial development phase. Issues, feature proposals, and pull requests will be welcomed as the architecture becomes stable.

When contributing:

1. Create a focused branch.
2. Follow WordPress Coding Standards.
3. Include tests where applicable.
4. Explain the problem and solution in the pull request.
5. Keep changes focused and backward-compatible.

## License

This project is planned to be released under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) license, consistent with WordPress plugin licensing practices.

## Author

**Salman Butt**

- GitHub: [@salman0butt](https://github.com/salman0butt)
- Portfolio: [salman-butt.vercel.app](https://salman-butt.vercel.app/)

---

Built as an open-source WordPress engineering project focused on secure API integration, distributed content workflows, extensibility, and maintainable plugin architecture.
