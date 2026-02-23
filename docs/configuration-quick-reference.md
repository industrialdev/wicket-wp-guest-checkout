# Configuration Quick Reference

This document summarizes the currently supported configuration points in Wicket Guest Checkout.

## Admin Settings (Recommended)

Use **Wicket -> Settings -> Integrations -> Guest Checkout** (`/wp-admin/admin.php?page=wicket-settings&tab=integrations&section=guest-checkout`).

### Available Fields

- **Token Expiry (days)**
  - Stored in `wicket_settings[wicket_admin_settings_guest_payment_token_expiry_days]`
  - Default: `7`
- **Email Subject Template**
  - Stored in `wicket_settings[wicket_admin_settings_guest_payment_email_subject_template]`
- **Email Body Template** (full HTML supported)
  - Stored in `wicket_settings[wicket_admin_settings_guest_payment_email_body_template]`

### Template Placeholders

- `<code>{site_name}</code>`
- `<code>{member_name}</code>`
- `<code>{order_number}</code>`
- `<code>{order_total}</code>`
- `<code>{payment_link}</code>` (HTML anchor)
- `<code>{payment_url}</code>` (raw URL)
- `<code>{expiry_date}</code>`
- `<code>{subscription_details}</code>` (HTML snippet)

## Integration Filters

### Email Integration Message in WooCommerce Emails

| Filter | Default | Description |
|---|---:|---|
| `wicket/wooguestpay/email_integration_enabled` | `false` | Enables/disables automatic guest payment message insertion in WooCommerce pending-order emails. |

```php
add_filter('wicket/wooguestpay/email_integration_enabled', '__return_true');
```

### PDF Integration Message

| Filter | Default | Description |
|---|---:|---|
| `wicket/wooguestpay/pdf_integration_enabled` | `true` | Enables/disables automatic guest payment message insertion in supported PDF invoice outputs. |

```php
add_filter('wicket/wooguestpay/pdf_integration_enabled', '__return_false');
```

### Token Expiry

| Filter | Default | Description |
|---|---:|---|
| `wicket/wooguestpay/token_expiry_days` | `7` | Number of days before generated guest payment tokens expire. |

```php
add_filter('wicket/wooguestpay/token_expiry_days', function ($days) {
    return 14;
});
```

## Core Validation Filters

| Filter | Description |
|---|---|
| `wicket_guest_payment_allowed_order_statuses` | Allowed WooCommerce order statuses for token validation. |
| `wicket_guest_payment_allowed_subscription_statuses` | Allowed subscription statuses when validating subscription-linked orders. |
| `wicket_guest_payment_encryption_keys` | Encryption key set used for token validation/decryption compatibility. |

## Admin-Sent Guest Payment Email Template Filters

These apply to the dedicated guest payment email sent from order admin actions.

### Subject

```php
add_filter(
    'wicket_guest_payment_email_subject',
    function ($subject, $order, $token, $placeholders, $recipient_email, $user_data) {
        return '[Payment Link] ' . $subject;
    },
    10,
    6
);
```

### Body Content

```php
add_filter(
    'wicket_guest_payment_email_content',
    function ($html, $order, $token, $placeholders, $recipient_email, $user_data) {
        return '<div style="padding:12px 0">' . $html . '</div>';
    },
    10,
    6
);
```

### Headers

```php
add_filter(
    'wicket_guest_payment_email_headers',
    function (array $headers, $order, $token, $placeholders, $recipient_email, $user_data) {
        $headers[] = 'Reply-To: billing@example.com';

        return $headers;
    },
    10,
    6
);
```

### Optional HTML Sanitization

Full HTML is allowed by default. To enforce sanitization:

```php
add_filter('wicket_guest_payment_email_sanitize_html', '__return_true');
```

Optionally customize allowed tags:

```php
add_filter(
    'wicket_guest_payment_email_allowed_html',
    function (array $allowed_html) {
        $allowed_html['img'] = [
            'src' => true,
            'alt' => true,
            'style' => true,
            'width' => true,
            'height' => true,
        ];

        return $allowed_html;
    }
);
```

## WordPress Options (Legacy/Fallback)

These keys are still read for backward compatibility:

- `wicket_guest_payment_token_expiry_days`
- `wicket_guest_payment_email_subject_template`
- `wicket_guest_payment_email_body_template`
- `wicket_guest_payment_enable_email_integration`
- `wicket_guest_payment_enable_pdf_integration`

Prefer using **Wicket Settings** keys for new implementations.

## Constants

```php
// Optional custom encryption material
define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', 'your-custom-encryption-key');
define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');

// Optional debug flag
define('WICKET_GUEST_PAYMENT_DEBUG', true);
```

## Related Docs

- [Email Integration Configuration](email-integration.md)
- [PDF Integration Configuration](pdf-integration.md)
- [Email Template Customization](email-template-customization.md)
