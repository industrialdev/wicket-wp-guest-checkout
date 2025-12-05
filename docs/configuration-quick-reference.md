# Configuration Quick Reference

This document provides a quick reference for all available configuration filters and constants for the Wicket Guest Checkout plugin.

## Core Integration Filters

### Email Integration

| Filter | Default | Description |
|--------|---------|-------------|
| `wicket/wooguestpay/email_integration_enabled` | `false` | Enable/disable guest payment links in WooCommerce emails |

**Enable Example:**
```php
add_filter('wicket/wooguestpay/email_integration_enabled', '__return_true');
```

**Conditional Example:**
```php
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled, $order) {
    return $order && $order->get_total() > 100; // Only for orders over $100
}, 10, 2);
```

### PDF Integration

| Filter | Default | Description |
|--------|---------|-------------|
| `wicket/wooguestpay/pdf_integration_enabled` | `false` | Enable/disable guest payment links in PDF invoices |

**Enable Example:**
```php
add_filter('wicket/wooguestpay/pdf_integration_enabled', '__return_true');
```

**Document Type Example:**
```php
add_filter('wicket/wooguestpay/pdf_integration_enabled', function($enabled, $document) {
    return $document && method_exists($document, 'get_type') && $document->get_type() === 'invoice';
}, 10, 2);
```

## Security Configuration

### Token Management

| Filter | Default | Description |
|--------|---------|-------------|
| `wicket/wooguestpay/token_expiry_days` | `7` | Number of days before guest payment tokens expire |

**Extend Expiry:**
```php
add_filter('wicket/wooguestpay/token_expiry_days', function($days) {
    return 14; // 14 days instead of 7
});
```

**Contextual Expiry:**
```php
add_filter('wicket/wooguestpay/token_expiry_days', function($days, $context) {
    if ($context === 'pdf') {
        return 21; // Longer expiry for PDFs
    }
    return $days;
}, 10, 2);
```

## Constants (wp-config.php)

### Enable Constants

```php
// Enable email integration
define('WICKET_GUEST_PAYMENT_ENABLE_EMAIL_INTEGRATION', true);

// Enable PDF integration
define('WICKET_GUEST_PAYMENT_ENABLE_PDF_INTEGRATION', true);
```

### Disable Constants (Takes Precedence)

```php
// Disable email integration (overrides enable)
define('WICKET_GUEST_PAYMENT_DISABLE_EMAIL_INTEGRATION', true);

// Disable PDF integration (overrides enable)
define('WICKET_GUEST_PAYMENT_DISABLE_PDF_INTEGRATION', true);
```

### Security Constants

```php
// Custom encryption key (optional)
define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', 'your-custom-encryption-key');

// Custom encryption method (optional)
define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');

// Enable debug mode
define('WICKET_GUEST_PAYMENT_DEBUG', true);
```

## WordPress Options

### Enable/Disable Options

```php
// Enable email integration
update_option('wicket_guest_payment_enable_email_integration', true);

// Enable PDF integration
update_option('wicket_guest_payment_enable_pdf_integration', true);

// Disable integration
update_option('wicket_guest_payment_enable_email_integration', false);
update_option('wicket_guest_payment_enable_pdf_integration', false);
```

## Conditional Integration Examples

### Order-Based Conditions

```php
// Enable only for specific order statuses
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled, $order) {
    return $order && in_array($order->get_status(), ['pending', 'processing']);
}, 10, 2);

// Enable only for orders with specific products
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled, $order) {
    if (!$order) return $enabled;

    $target_products = [123, 456, 789]; // Product IDs
    foreach ($order->get_items() as $item) {
        if ($item instanceof WC_Order_Item_Product) {
            if (in_array($item->get_product_id(), $target_products)) {
                return true;
            }
        }
    }
    return $enabled;
}, 10, 2);

// Enable only for specific order total ranges
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled, $order) {
    if (!$order) return $enabled;

    $total = $order->get_total();
    return $total >= 50 && $total <= 1000; // Between $50 and $1000
}, 10, 2);
```

### User-Based Conditions

```php
// Enable only for specific user roles
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled) {
    $user = wp_get_current_user();
    return in_array('corporate_client', $user->roles) || in_array('wholesale_customer', $user->roles);
});

// Enable only for guest users (no logged-in user)
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled) {
    return !is_user_logged_in();
});
```

### Time-Based Conditions

```php
// Enable only during business hours
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled) {
    $current_time = current_time('H');
    return $current_time >= 9 && $current_time <= 17; // 9 AM to 5 PM
});

// Enable only on weekdays
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled) {
    $day = current_time('w');
    return $day >= 1 && $day <= 5; // Monday to Friday
});
```

## Customization Filters

### Email Customization

```php
// Customize email message
add_filter('wicket_guest_payment_email_content', function($content, $order, $token) {
    return '<p>Custom email content with order #' . $order->get_id() . '</p>' . $content;
}, 10, 3);

// Customize email subject
add_filter('woocommerce_email_subject_new_order', function($subject, $order) {
    if ($order->get_status() === 'pending') {
        return '[Payment Link] ' . $subject;
    }
    return $subject;
}, 10, 2);
```

### PDF Customization

```php
// Customize PDF link styling
add_filter('wicket/wooguestpay/pdf_link_style', function($style) {
    return 'color: #0066cc; font-weight: bold; font-size: 14px;';
});

// Customize PDF message
add_filter('wicket/wooguestpay/pdf_message', function($message, $link) {
    return sprintf(
        '<div style="border-top:1px solid #ccc;padding-top:16px;margin-top:24px;">
            <strong>Alternative Payment:</strong><br>
            <a href="%s" style="%s">Click here for secure payment link</a>
        </div>',
        esc_url($link),
        'color:#0066cc;font-weight:bold;text-decoration:none;'
    );
}, 10, 2);
```

### URL and Path Customization

```php
// Custom cart URL for guest payments
add_filter('wicket_guest_payment_cart_url', function($url) {
    return 'https://custom-domain.com/custom-cart-page';
});

// Custom redirect after payment
add_filter('wicket_guest_payment_redirect_url', function($url, $order_id) {
    return 'https://custom-domain.com/thank-you?order=' . $order_id;
}, 10, 2);
```

## Testing Configuration

### Check Current Settings

```php
// Check if email integration is enabled
$email_enabled = apply_filters('wicket/wooguestpay/email_integration_enabled', false);
error_log('Email integration enabled: ' . ($email_enabled ? 'Yes' : 'No'));

// Check if PDF integration is enabled
$pdf_enabled = apply_filters('wicket/wooguestpay/pdf_integration_enabled', false);
error_log('PDF integration enabled: ' . ($pdf_enabled ? 'Yes' : 'No'));

// Check token expiry
$expiry_days = apply_filters('wicket/wooguestpay/token_expiry_days', 7);
error_log('Token expiry days: ' . $expiry_days);
```

### Debug Hook Execution

```php
// Debug email hook
add_action('woocommerce_email_before_order_table', function($order, $sent_to_admin, $plain_text, $email) {
    error_log('Email hook executed for order: ' . $order->get_id());
    error_log('Email integration enabled: ' . (apply_filters('wicket/wooguestpay/email_integration_enabled', false) ? 'Yes' : 'No'));
}, 1, 4);

// Debug PDF hook
add_action('wpo_wcpdf_after_order_details', function($document, $order) {
    error_log('PDF hook executed for order: ' . $order->get_id());
    error_log('PDF integration enabled: ' . (apply_filters('wicket/wooguestpay/pdf_integration_enabled', false) ? 'Yes' : 'No'));
}, 1, 2);
```

## Common Use Cases

### B2B/Corporate Clients

```php
// Enable for B2B customers only
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled, $order) {
    if (!$order) return $enabled;

    $user_id = $order->get_user_id();
    if ($user_id) {
        $user = get_user_by('id', $user_id);
        return in_array('b2b_client', $user->roles);
    }

    // Check for billing company
    return !empty($order->get_billing_company());
}, 10, 2);
```

### Subscription Renewals

```php
// Enable for subscription-related orders
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled, $order) {
    if (!$order) return $enabled;

    // Check if order contains subscriptions
    if (function_exists('wcs_order_contains_subscription')) {
        return wcs_order_contains_subscription($order);
    }

    return $enabled;
}, 10, 2);
```

### High-Value Orders

```php
// Enable only for orders above threshold
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled, $order) {
    if (!$order) return $enabled;

    $threshold = get_option('wicket_guest_payment_min_amount', 500);
    return $order->get_total() >= $threshold;
}, 10, 2);
```

## Related Documentation

- [Email Integration Configuration](email-integration.md) - Detailed email setup
- [PDF Integration Configuration](pdf-integration.md) - Detailed PDF setup
- [Token Security](security.md) - Security considerations
- [Advanced Configuration](advanced-config.md) - Advanced customization options