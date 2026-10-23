# Repository Guidelines

## What This Plugin Is

WordPress/WooCommerce plugin that enables secure guest payment functionality through time-limited, encrypted payment links. Guests can complete payment on behalf of registered users without authentication.

**Critical security context**: this plugin handles sensitive payment flows with temporary authentication. All changes must maintain strict security controls around token encryption, session management, and access restrictions.

For architecture (system flow, class responsibilities, data storage keys, security patterns, WooCommerce integration points, constants, activation behavior) and the testing setup: `@see atlas://packages/wicket-wp-guest-checkout.md`.

## Documentation

The Atlas knowledge base holds the full engineering and product documentation for the whole Wicket stack. Start at `@see atlas://packages/wicket-wp-guest-checkout.md` for this package. The repo docs/ folder, when present, holds only public end-consumer guides; engineering material never lives beside the code.

## Development Commands

```bash
composer install
composer test              # Pest suite
composer test:unit         # unit tests only
composer test:browser      # requires WICKET_BROWSER_BASE_URL
composer lint              # code style check (dry-run)
composer format            # auto-fix code style
composer check             # lint + test
composer production        # install without dev dependencies
```

## Coding Standards

- PHP 8.3+ (composer.json enforces it); all files use `declare(strict_types=1);`.
- PSR-12 + WordPress modifications; type hints on all method parameters and return types.
- PSR-4 autoloading via Composer (`Wicket\GuestPayment` namespace); class files use PascalCase names.
- Sanitize all input, escape all output, nonces on all admin actions.
- Use WooCommerce CRUD methods (HPOS compatible) — no direct wpdb queries.
- i18n: all strings use the `wicket-wgc` text domain.

## Non-Negotiable Security Patterns

- Token handling goes through the Core class methods only — never manual encryption.
- Rate limiting at token validation: 5 attempts per 15 minutes per IP via transients; returns `WP_Error` on limit exceeded.
- Session cleanup after payment is automatic; manual trigger available for support flows.

## Release & Branch Workflow

All work happens on branches. `main` is locked; changes land via peer-reviewed Pull Request. Never commit to `main` directly, and never push or open a PR without explicit human approval.

Merging a PR to `main` **auto-releases** via the `wicket-release-bot` GitHub App: version bump, `CHANGELOG.md` update, git tag. Never bump versions or create tags by hand. The bump level comes from a marker in the PR title (squash-merge makes it the commit message): _(none)_ / `#patch` = patch, `#minor`, `#major`, or `#norelease` (no release; use for docs/tooling-only merges). Conventional commit prefixes (`feat:`, `fix:`, `docs:`, ...) drive changelog grouping; a `!` (e.g. `feat!:`) flags a BREAKING change.
