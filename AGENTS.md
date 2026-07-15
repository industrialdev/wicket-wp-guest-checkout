## Project Overview

WordPress/WooCommerce plugin that enables secure guest payment functionality through time-limited, encrypted payment links. Guests can complete payment on behalf of registered users without authentication.

**Critical Security Context**: This plugin handles sensitive payment flows with temporary authentication. All changes must maintain strict security controls around token encryption, session management, and access restrictions.

## Development Commands

### Composer Scripts
```bash
# Install dependencies
composer install

# Testing (uses Pest framework)
composer test                        # Run Pest tests
composer test:coverage               # Run tests with HTML coverage report (output: coverage/)
composer test:unit                   # Run Pest unit tests only
composer test:browser                # Run Pest browser tests (requires WICKET_BROWSER_BASE_URL)

# Code Quality
composer lint                        # Check code style (dry-run)
composer format                      # Auto-fix code style issues
composer check                       # Run lint + test

# Production
composer production                  # Install without dev dependencies for production
composer setup-hooks                 # Install git pre-push hooks
```

### Testing Individual Files
```bash
# Run specific test file (Pest)
./vendor/bin/pest tests/unit/WicketGuestPaymentCore.pest.php

# Run all unit tests
./vendor/bin/pest --testsuite unit

# Run browser tests (requires local WordPress with plugin active)
WICKET_BROWSER_BASE_URL=https://localhost ./vendor/bin/pest --testsuite browser
```

### Data Storage

**Order Meta Keys** (Permanent):
```
_wgp_guest_payment_token_encrypted    # Encrypted payment token
_wgp_guest_payment_token_hash         # HMAC validation hash
_wgp_guest_payment_token_created      # Creation timestamp
_wgp_guest_payment_user_id            # Associated user ID
_wgp_guest_payment_email              # Guest email
_wgp_guest_payment_generation_method  # 'email' or 'manual'
_wgp_receipt_access_token             # Receipt token
_wgp_receipt_token_created            # Receipt timestamp
```

**User Meta** (Temporary - cleaned up):
```
_wgp_guest_session_token_validation   # Session validation hash
_wgp_original_order_id                # Duplicate prevention
_wgp_cart_data                        # Cart restoration data
_wgp_admin_pay_impersonated_user_id   # AdminPay: Original admin user ID
_wgp_admin_pay_original_order_id      # AdminPay: Order being paid
```

**Transients** (Temporary):
```
wgp_map_{key}                         # Cart key → User ID mapping
wgp_cart_{key}                        # Serialized cart data
guest_pay_limit_{ip}                  # Rate limiting counter
wgp_admin_pay_{token}                 # AdminPay session (15min TTL)
```

## Coding Standards

### PHP Requirements
- **PHP 8.3+** strict requirement (composer.json enforces >=8.3)
- **Strict types**: All files use `declare(strict_types=1);`
- **Standards**: PSR-12 + WordPress modifications
- **Type hints**: Required for all method parameters and return types
- **Autoloading**: PSR-4 via Composer (`Wicket\GuestPayment` namespace)

### Security Requirements
- **Input**: Sanitize ALL user input
- **Output**: Escape ALL output (esc_html, esc_attr, esc_url, wp_kses_post)
- **Nonces**: Required for all admin actions
- **SQL**: Use WooCommerce CRUD methods (HPOS compatible) - NO direct wpdb queries
- **Capability checks**: verify_nonce + current_user_can before admin operations

### WordPress/WooCommerce Patterns
- Use WooCommerce CRUD methods for order operations
- Leverage WC_Logger for debugging (logs appear in WooCommerce → Status → Logs)
- Hook into WooCommerce email system
- i18n: All strings use 'wicket-wgc' text domain

## Critical Security Patterns

### Token Handling
```php
// ALWAYS use Core class methods - never manual encryption
$core->generate_guest_payment_token($order_id, $guest_email);
$core->validate_guest_payment_token($token, $order);
$core->invalidate_guest_payment_token($order_id);
```

### Rate Limiting
- Enforced at token validation: 5 attempts per 15 minutes per IP
- Uses transients: `guest_pay_limit_{ip_address}`
- Returns `WP_Error` on limit exceeded

### Session Cleanup
- Automatic after payment completion
- Manual trigger: `$auth->cleanup_guest_session($user_id)`
- Removes user meta, transients, logs out user

## Common Modification Patterns

### Extending Token Expiry
```php
add_action('init', function() {
    $core = new WicketGuestPaymentCore();
    $core->set_token_expiry_days(14); // Change from default 7
});
```

### Filtering Allowed Order Statuses
```php
add_filter('wicket_guest_payment_allowed_order_statuses', function($statuses) {
    $statuses[] = 'custom-status';
    return $statuses;
});
```

### Customizing Email Content
```php
add_filter('wicket_guest_payment_email_content', function($content, $order, $token) {
    // Modify $content
    return $content;
}, 10, 3);
```

## WooCommerce Integration Points

### HPOS Compatibility
- Use order CRUD methods: `$order->get_meta()`, `$order->update_meta_data()`, `$order->save()`
- Avoid direct post meta access
- Cart operations use WC session and cart classes

### Email System
- Integrates with WC_Email infrastructure
- Sends via WooCommerce mailer
- Respects WC email settings

### Subscription Support
- Allows 'active' status for subscription renewals
- Filter: `wicket_guest_payment_allowed_subscription_statuses`

## Release & Branch Workflow
All work happens on branches. `main` is locked; changes land via peer-reviewed
Pull Request (devs cross-review each other). Never commit to `main` directly, and never push or open a
PR without explicit human approval.

Merging a PR to `main` **auto-releases** via the `wicket-release-bot` GitHub
App: version bump, `CHANGELOG.md` update, git tag. Never bump versions or
create tags by hand. The bump level comes from a marker in the PR title
(squash-merge makes it the commit message): _(none)_ / `#patch` = patch, `#minor`,
`#major`, or `#norelease` (no release; use for docs/tooling-only merges).
Conventional commit prefixes (`feat:`, `fix:`, `docs:`, ...) drive changelog
grouping; a `!` (e.g. `feat!:`) flags a BREAKING change.
