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

## Architecture

### Core System Flow
1. **Token Generation** (Admin) → Encrypted token stored in order meta
2. **Guest Access** → Token validated → Temporary user authentication created
3. **Cart Population** → Original order items + prices restored
4. **Payment** → Processed on existing order (no new order created)
5. **Cleanup** → Token invalidated, session destroyed, guest logged out
6. **Receipt** → 30-day access link generated

### Class Responsibilities

**WicketGuestPayment** (Main Coordinator - Singleton)
- Initializes all components via `plugin_setup()`
- Manages component lifecycle
- No business logic - pure orchestration

**WicketGuestPaymentCore** (Token & Cart Management)
- AES-256-CBC token encryption/decryption
- HMAC-SHA256 hash validation
- Cart data preservation and restoration
- Order meta management
- Token expiry validation (default: 7 days)

**WicketGuestPaymentAuth** (Authentication & Access Control)
- Temporary user authentication
- Session management with validation hash
- Access restrictions (blocks admin, limits to checkout pages)
- IP-based rate limiting (5 attempts per 15 minutes)
- Automatic cleanup after payment

**WicketGuestPaymentAdmin** (Admin Interface)
- Order meta box UI
- Manual link generation
- Email trigger
- Link invalidation
- Order notes tracking

**WicketGuestPaymentAdminPay** (Admin Pay For Customer)
- Admin "Pay For Customer" flow with impersonation
- Temporary admin session with auto-return
- 15-minute TTL transients
- Secure cookie-based authentication
- Shipping info display on order-pay page

**WicketGuestPaymentConfig** (Configuration Management)
- Centralized plugin settings
- Email/PDF integration toggles
- Token expiry configuration
- Plugin action links (Settings, Docs)
- Filter registration

**WicketGuestPaymentEmail** (Notifications)
- Payment link email delivery
- Uses WooCommerce email system
- Filterable subject/content

**WicketGuestPaymentReceipt** (Post-Payment)
- Receipt access token generation
- 30-day validity
- PDF invoice integration hooks

**WicketGuestPaymentInvoice** (PDF Integration)
- WooCommerce PDF Invoices & Packing Slips integration
- Receipt page invoice display

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

### File Naming
```
class-wicket-guest-payment-*.php      # Classes
trait-wicket-guest-payment-*.php      # Traits
abstract-wicket-guest-payment-*.php   # Abstract classes
```

**Note**: Recent refactor changed to PascalCase filenames without prefix:
```
WicketGuestPayment.php
WicketGuestPaymentCore.php
AbstractWicketGuestPaymentComponent.php
TraitWicketGuestPaymentLogger.php
```

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

## Testing Context

### Test Framework
**Pest PHP** - Modern PHP testing framework (v4+)
- Syntax: Expectation API with describe/it blocks
- File naming: `*.pest.php` (e.g., `WicketGuestPaymentCore.pest.php`)
- Dependencies: Brain Monkey for WordPress function mocking
- Browser tests: Pest Browser plugin with Playwright

### Test Suites
1. **Unit Tests** (`tests/unit/*.pest.php`)
   - Mock WordPress/WooCommerce functions via Brain Monkey
   - Extend `AbstractTestCase` for common setup
   - Test individual class methods in isolation

2. **Browser Tests** (`tests/Browser/*.pest.php`)
   - End-to-end functional tests
   - Requires running WordPress instance
   - Uses Playwright for browser automation
   - Set `WICKET_BROWSER_BASE_URL` environment variable

### Running Tests
```bash
# All tests
./vendor/bin/pest

# Specific suite
./vendor/bin/pest --testsuite unit
./vendor/bin/pest --testsuite browser

# Single file
./vendor/bin/pest tests/unit/WicketGuestPaymentCore.pest.php

# With coverage
./vendor/bin/pest --coverage-html coverage
```

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

## Debugging

### Enable Logging
Plugin uses WooCommerce logger:
```php
$this->log('debug', 'Message', ['context' => 'data']);
```

View logs: **WooCommerce → Status → Logs → Select "wicket-guest-payment"**

### WordPress Debug Mode
```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

## Constants

```php
WICKET_GUEST_CHECKOUT_VERSION         # Plugin version
WICKET_GUEST_CHECKOUT_FILE            # Main plugin file path
WICKET_GUEST_CHECKOUT_PATH            # Plugin directory path
WICKET_GUEST_CHECKOUT_URL             # Plugin URL
WICKET_GUEST_CHECKOUT_BASENAME        # Plugin basename
WICKET_GUEST_PAYMENT_ENCRYPTION_KEY   # AES-256 encryption key (override in wp-config.php)
WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD # Encryption method (default: aes-256-cbc)
```

## Plugin Activation/Deactivation

**Activation** (`wicket_guest_checkout_activate`):
- Verifies PHP 8.2+ (note: composer.json enforces >=8.3, plugin header says 8.2)
- Checks WooCommerce active
- Sets rewrite rules flush flag
- Records activation timestamp

**Deactivation** (`wicket_guest_checkout_deactivate`):
- Flushes rewrite rules
- Removes flags
- Does NOT delete data (intentional - preserve order history)
