# Wicket Guest Checkout

A secure WooCommerce plugin that enables guest payment functionality through time-limited, encrypted payment links.

## Overview

Wicket Guest Checkout allows WordPress administrators to generate secure payment links for WooCommerce orders. These links can be shared with guests who can then complete payment on behalf of registered users without needing to log in or create an account.

## Features

### Core Functionality
- **Secure Token System**: AES-256-CBC encrypted tokens with HMAC validation
- **Guest Payment Flow**: Streamlined checkout process for guest payers
- **Session Management**: Temporary authentication with automatic cleanup
- **Cart Preservation**: Original order items and custom pricing maintained
- **Access Restrictions**: Guest sessions limited to checkout pages only

### Admin Features
- **Order Meta Box**: Manage guest payment links directly from order admin
- **Email Integration**: Automatically send payment links via email
- **Manual Link Generation**: Create and copy links without sending email
- **Link Invalidation**: Revoke payment links at any time
- **Order Notes**: Automatic tracking of guest payment activities

### Security
- Token encryption using WordPress authentication keys
- HMAC-SHA256 hash verification
- IP-based rate limiting (5 attempts per 15 minutes)
- Time-limited tokens (7-day default expiry)
- Automatic session cleanup after payment
- Duplicate order prevention
- Brute force protection

### Receipt System
- Post-payment receipt access
- 30-day receipt link validity
- Email receipt delivery
- PDF invoice integration support

## Requirements

- **WordPress**: 6.0+
- **WooCommerce**: 8.0+
- **PHP**: 8.2+
- **SSL Certificate**: Recommended for production

## Installation

### From GitHub

```bash
cd wp-content/plugins
git clone https://github.com/wicket/wicket-guest-checkout.git
cd wicket-guest-checkout
```

Then activate the plugin through WordPress admin.

### Manual Upload

1. Download the latest release ZIP
2. Upload through WordPress Admin → Plugins → Add New → Upload
3. Activate the plugin

## Configuration

### Basic Setup

The plugin works out of the box with WooCommerce. No additional configuration required.

### Encryption Keys (Recommended)

For enhanced security, add custom encryption keys to `wp-config.php`:

```php
define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', 'your-unique-32-char-key-here');
define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
```

If not defined, the plugin uses WordPress `SECURE_AUTH_KEY` and `AUTH_KEY` as fallback.

### Token Expiry

Modify token expiry time using the core class:

```php
add_action('init', function() {
    $core = new Wicket_Guest_Payment_Core();
    $core->set_token_expiry_days(14); // 14 days instead of default 7
});
```

### Allowed Order Statuses

Filter which order statuses can use guest payment:

```php
add_filter('wicket_guest_payment_allowed_order_statuses', function($statuses) {
    $statuses[] = 'custom-status';
    return $statuses;
});
```

Default allowed statuses: `pending`, `failed`, `on-hold`

For subscriptions, `active` status is also allowed by default.

## Usage

### For Administrators

1. Navigate to a WooCommerce order in admin
2. Locate the "Guest Payment" meta box in the sidebar
3. Choose one of two options:

**Option 1: Generate and Send Email**
- Enter guest email address
- Click "Generate & Send Email"
- Guest receives payment link via email

**Option 2: Manual Link Generation**
- Click "Generate Link"
- Copy the generated URL
- Share via any channel (email, SMS, etc.)

### For Guests

1. Click the payment link received
2. Automatically logged in with restricted access
3. Review cart (pre-filled with order items)
4. Complete payment through checkout
5. View receipt and automatically logged out

## Architecture

### Class Structure

```
wicket-guest-checkout.php              # Main plugin file
├── src/
│   ├── class-wicket-guest-payment.php        # Main coordinator (Singleton)
│   ├── class-wicket-guest-payment-core.php   # Token & cart management
│   ├── class-wicket-guest-payment-admin.php  # Admin interface
│   ├── class-wicket-guest-payment-email.php  # Email notifications
│   ├── class-wicket-guest-payment-auth.php   # Authentication & restrictions
│   ├── class-wicket-guest-payment-invoice.php # Invoice integration
│   └── class-wicket-guest-payment-receipt.php # Receipt management
```

### Data Flow

```
1. Admin generates token → Encrypted & stored in order meta
2. Guest clicks link → Token validated → User authenticated
3. Cart populated → Checkout restricted session created
4. Payment completed → Token invalidated → Session cleaned up
5. Receipt generated → 30-day access link created
```

### Database Storage

**Order Meta Keys:**
- `_wgp_guest_payment_token_encrypted`: Encrypted payment token
- `_wgp_guest_payment_token_hash`: HMAC hash for validation
- `_wgp_guest_payment_token_created`: Token creation timestamp
- `_wgp_guest_payment_user_id`: Associated user ID
- `_wgp_guest_payment_email`: Guest email address
- `_wgp_guest_payment_generation_method`: `email` or `manual`
- `_wgp_receipt_access_token`: Receipt access token
- `_wgp_receipt_token_created`: Receipt token creation timestamp

**User Meta (Temporary):**
- `_wgp_guest_session_token_validation`: Session validation hash
- `_wgp_original_order_id`: Order ID for duplicate prevention
- `_wgp_cart_data`: Cart data for restoration

**Transients (Temporary):**
- `wgp_map_{key}`: Secure cart key to user ID mapping
- `wgp_cart_{key}`: Serialized cart data
- `guest_pay_limit_{ip}`: Rate limiting counter

## Hooks & Filters

### Actions

```php
// Before guest payment link generation
do_action('wicket_guest_payment_before_generate', $order_id, $guest_email);

// After successful guest payment
do_action('wicket_guest_payment_completed', $order_id, $user_id);

// Before token invalidation
do_action('wicket_guest_payment_before_invalidate', $order_id);
```

### Filters

```php
// Modify allowed order statuses
apply_filters('wicket_guest_payment_allowed_order_statuses', $statuses);

// Modify allowed subscription statuses
apply_filters('wicket_guest_payment_allowed_subscription_statuses', $statuses);

// Customize email subject
apply_filters('wicket_guest_payment_email_subject', $subject, $order);

// Customize email content
apply_filters('wicket_guest_payment_email_content', $content, $order, $token);
```

## Development

### Setup Development Environment

```bash
# Clone repository
git clone https://github.com/wicket/wicket-guest-checkout.git
cd wicket-guest-checkout

# Install in WordPress
ln -s $(pwd) /path/to/wordpress/wp-content/plugins/wicket-guest-checkout
```

### Coding Standards

- PHP: PSR-12 with WordPress modifications
- PHP Version: 8.2+
- Strict types enabled (`declare(strict_types=1)`)
- WooCommerce CRUD methods for HPOS compatibility
- All user input sanitized and validated
- All output escaped

### Logging

The plugin uses WooCommerce logger:

```php
// View logs
WooCommerce → Status → Logs → Select "wicket-guest-payment"
```

### Testing

```bash
# Manual testing checklist
- [ ] Generate payment link from order admin
- [ ] Send payment link via email
- [ ] Complete guest payment flow
- [ ] Verify order status update
- [ ] Confirm token invalidation
- [ ] Test rate limiting
- [ ] Verify session cleanup
- [ ] Check receipt access
```

## Security Considerations

1. **Always use HTTPS in production**
2. **Define custom encryption keys in wp-config.php**
3. **Regularly update WordPress and WooCommerce**
4. **Monitor failed token validation attempts**
5. **Review logs for suspicious activity**

## Troubleshooting

### Payment link doesn't work

- Check token hasn't expired (7 days default)
- Verify WooCommerce is active
- Ensure order status is `pending`, `failed`, or `on-hold`
- Check WooCommerce logs for errors

### Cart is empty after clicking link

- Verify WooCommerce session is working
- Check product availability and stock status
- Review cart restoration logs

### Guest can access admin pages

- Check guest session cookie is set
- Verify `prevent_guest_admin_access` hook is running
- Review server cookie configuration

## Support

- **Issues**: [GitHub Issues](https://github.com/wicket/wicket-guest-checkout/issues)
- **Documentation**: [Wiki](https://github.com/wicket/wicket-guest-checkout/wiki)
- **Discussions**: [GitHub Discussions](https://github.com/wicket/wicket-guest-checkout/discussions)

## License

GPL v2 or later

## Credits

Developed by [Wicket](https://wicket.io)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.
