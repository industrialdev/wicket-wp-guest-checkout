# Email Integration Configuration

This document explains how to configure and enable the guest payment email integration feature for the Wicket Guest Checkout plugin.

## Overview

The email integration feature automatically adds guest checkout URLs to WooCommerce emails sent for pending orders. This allows recipients to complete payment on behalf of the original customer.

**Security Note:** This feature is **disabled by default** and requires explicit activation to ensure secure implementation.

## How It Works

When enabled, the plugin will:

1. **Automatically detect** pending orders in WooCommerce emails
2. **Dynamically generate** secure guest payment tokens
3. **Inject guest payment links** into email content
4. **Provide user-friendly message** for third-party payers

## Configuration Options

### Enable Email Integration

To enable automatic guest payment URL generation in emails, you have several options:

#### Method 1: Filter (Recommended)

Add to your theme's `functions.php` or a custom plugin:

```php
/**
 * Enable guest payment email integration
 */
add_filter('wicket/wooguestpay/email_integration_enabled', '__return_true');
```

#### Method 2: WordPress Option

Enable via WordPress options API:

```php
// Enable programmatically
update_option('wicket_guest_payment_enable_email_integration', true);

// Disable programmatically
update_option('wicket_guest_payment_enable_email_integration', false);
```

### Disable Email Integration

You can explicitly disable the feature even if enabled by other methods:

#### Method 1: Filter

```php
/**
 * Disable guest payment email integration
 */
add_filter('wicket/wooguestpay/email_integration_enabled', '__return_false');
```

#### Method 2: WordPress Option

```php
// Disable via WordPress option
update_option('wicket_guest_payment_enable_email_integration', false);
```

## Email Content

When enabled, guest payment information is automatically added to WooCommerce emails with the message:

> "Will someone else be paying this invoice? Use our [guest payment](link) link to complete this transaction."

### Email Hooks

The integration uses the following WooCommerce hooks:

- **Hook:** `woocommerce_email_before_order_table`
- **Priority:** 15
- **Target:** All pending order emails

### Supported Email Types

- Customer Processing Order emails
- Customer Pending Order emails
- Admin emails for pending orders
- Custom email types that trigger the hook

## Conditional Activation

You can conditionally enable the feature based on specific criteria:

```php
/**
 * Enable email integration only for specific order types
 */
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled, $order = null) {
    // Enable only for orders over $100
    if ($order && $order->get_total() > 100) {
        return true;
    }

    return $enabled;
}, 10, 2);
```

```php
/**
 * Enable for specific user roles only
 */
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled) {
    // Check if current user has specific role
    if (current_user_can('manage_woocommerce')) {
        return true;
    }

    return $enabled;
});
```

```php
/**
 * Enable for specific products only
 */
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled, $order = null) {
    if (!$order) {
        return $enabled;
    }

    // Check if order contains specific products
    $target_products = [123, 456, 789]; // Product IDs

    foreach ($order->get_items() as $item) {
        if ($item instanceof WC_Order_Item_Product) {
            $product_id = $item->get_product_id();
            if (in_array($product_id, $target_products)) {
                return true;
            }
        }
    }

    return $enabled;
}, 10, 2);
```

## Token Security

- **Encryption:** AES-256-CBC encryption for token data
- **Validation:** HMAC-SHA256 hash validation
- **Expiry:** Tokens expire after 7 days (configurable)
- **Single-use:** Tokens are invalidated after payment completion

### Custom Token Expiry

You can customize the token expiry period:

```php
/**
 * Set custom token expiry for email integration
 */
add_filter('wicket/wooguestpay/token_expiry_days', function($days) {
    return 14; // Extend to 14 days
});
```

## Troubleshooting

### Emails Not Showing Guest Payment Links

1. **Check if integration is enabled:**
   ```php
   var_dump(apply_filters('wicket/wooguestpay/email_integration_enabled', false));
   ```

2. **Verify order status is "pending"**
   ```php
   // In email context
   if ($order && $order->get_status() === 'pending') {
       // Should show links
   }
   ```

3. **Check email hook is being triggered**
   ```php
   // Debug hook execution
   add_action('woocommerce_email_before_order_table', function($order, $sent_to_admin, $plain_text, $email) {
       error_log('Email hook triggered for order: ' . $order->get_id());
   }, 5, 4);
   ```

### Tokens Not Generated

1. **Check Core class is loaded:**
   ```php
   $main_plugin = WicketGuestPayment::get_instance();
   $core = $main_plugin->get_core();
   var_dump($core instanceof WicketGuestPaymentCore);
   ```

2. **Verify order has billing email:**
   ```php
   $billing_email = $order->get_billing_email();
   var_dump(is_email($billing_email));
   ```

## Performance Considerations

- **Tokens are generated on-demand** - no performance impact when disabled
- **Cached after generation** - subsequent requests use existing tokens
- **Minimal overhead** - only processes pending order emails
- **Database queries** - uses existing order meta (no additional queries)

## Debug Mode

Enable debug logging for troubleshooting:

```php
/**
 * Enable debug mode for email integration
 */
define('WICKET_GUEST_PAYMENT_DEBUG', true);

// Check logs in: WooCommerce > Status > Logs
```

## Related Documentation

- [PDF Integration Configuration](pdf-integration.md) - Configure PDF invoice integration
- [Token Security](security.md) - Token generation and security details
- [Advanced Configuration](advanced-config.md) - Additional configuration options