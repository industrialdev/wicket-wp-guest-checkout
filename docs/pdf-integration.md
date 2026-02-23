# PDF Integration Configuration

This document explains how guest payment links are appended to supported PDF invoice outputs.

## Overview

The PDF integration appends a guest payment message/link during PDF rendering for eligible orders.

- Hook used: `wpo_wcpdf_after_order_details`
- Runtime filter: `wicket/wooguestpay/pdf_integration_enabled`
- Runtime default: `true`
- Eligible order statuses: `pending`, `failed`, `on-hold`

## Enable/Disable PDF Integration

### Filter (recommended)

```php
// Disable globally
add_filter('wicket/wooguestpay/pdf_integration_enabled', '__return_false');

// Enable only for invoices
add_filter('wicket/wooguestpay/pdf_integration_enabled', function ($enabled, $document_type) {
    return $document_type === 'invoice';
}, 10, 2);
```

### Option API

```php
update_option('wicket_guest_payment_enable_pdf_integration', true);
// or false
```

## How Link Generation Works

When rendering, the integration:

1. Resolves the `WC_Order` object from hook arguments.
2. Checks feature toggle and allowed statuses.
3. Reuses existing valid token if present, or generates a new one.
4. Prints the guest payment message with a link.

## Token Expiry

PDF uses the same token expiry policy as the rest of guest checkout.

- Default: 7 days
- Wicket setting: `wicket_admin_settings_guest_payment_token_expiry_days`
- Filter: `wicket/wooguestpay/token_expiry_days`

```php
add_filter('wicket/wooguestpay/token_expiry_days', fn ($days) => 21);
```

## Customizing PDF Output

There are no dedicated built-in filters for PDF message style/text in current implementation.

Recommended approaches:

1. Disable built-in PDF integration with `wicket/wooguestpay/pdf_integration_enabled`.
2. Add your own renderer to `wpo_wcpdf_after_order_details`.

Example:

```php
add_filter('wicket/wooguestpay/pdf_integration_enabled', '__return_false');

add_action('wpo_wcpdf_after_order_details', function ($document, $order) {
    if (!($order instanceof WC_Order)) {
        return;
    }

    if (!$order->has_status(['pending', 'failed', 'on-hold'])) {
        return;
    }

    // Render your own custom message/link here.
}, 99, 2);
```

## Troubleshooting

### No guest message in PDF

1. Check toggle:

```php
var_dump(apply_filters('wicket/wooguestpay/pdf_integration_enabled', true, 'invoice'));
```

2. Verify order status is one of `pending`, `failed`, `on-hold`.
3. Ensure your PDF plugin triggers `wpo_wcpdf_after_order_details`.

### Token not generated

1. Confirm order has valid guest/billing email.
2. Confirm guest checkout core initialized correctly.
3. Check WooCommerce logs for `wicket-guest-payment` entries.

## Related Docs

- [Configuration Quick Reference](configuration-quick-reference.md)
- [Email Integration Configuration](email-integration.md)
