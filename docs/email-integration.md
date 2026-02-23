# Email Integration Configuration

This guide covers both email-related features in Wicket Guest Checkout.

## Two Email Features

### 1. WooCommerce Email Integration Message

This inserts a guest payment message/link into WooCommerce order emails via `woocommerce_email_before_order_table`.

- Enabled by filter: `wicket/wooguestpay/email_integration_enabled`
- Runtime default: `false`
- Target order status: `pending`

### 2. Admin-Sent Guest Payment Email Template

When admins click **Generate & Send Email** in the order Guest Payment meta box, this plugin sends a dedicated guest-payment email.

This email is now configurable from:

- **Wicket -> Settings -> Integrations -> Guest Checkout**
- Direct URL: `/wp-admin/admin.php?page=wicket-settings&tab=integrations&section=guest-checkout`

## Enable/Disable WooCommerce Email Integration Message

### Filter (recommended)

```php
add_filter('wicket/wooguestpay/email_integration_enabled', '__return_true');
```

### Option API

```php
update_option('wicket_guest_payment_enable_email_integration', true);
// or false
```

## Configure Admin-Sent Email Template

In **Guest Checkout** section, configure:

- **Token Expiry (days)**
- **Email Subject Template**
- **Email Body Template** (full HTML)

### Supported Placeholders

- `<code>{site_name}</code>`
- `<code>{member_name}</code>`
- `<code>{order_number}</code>`
- `<code>{order_total}</code>`
- `<code>{payment_link}</code>`
- `<code>{payment_url}</code>`
- `<code>{expiry_date}</code>`
- `<code>{subscription_details}</code>`

### HTML Behavior

- Body template is rendered as full HTML (no automatic paragraph conversion).
- `<code>{payment_link}</code>` resolves to an `<a>` tag.
- `<code>{subscription_details}</code>` resolves to HTML when applicable.

## Template Filters (Admin-Sent Email)

### Subject

```php
add_filter(
    'wicket_guest_payment_email_subject',
    function ($subject, $order, $token, $placeholders, $recipient_email, $user_data) {
        return '[Invoice Payment] ' . $subject;
    },
    10,
    6
);
```

### Body HTML

```php
add_filter(
    'wicket_guest_payment_email_content',
    function ($html, $order, $token, $placeholders, $recipient_email, $user_data) {
        return '<div class="my-wrapper">' . $html . '</div>';
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

### Optional Sanitization

By default, sanitization is off to allow full HTML control.

```php
add_filter('wicket_guest_payment_email_sanitize_html', '__return_true');
```

Optionally control allowed tags:

```php
add_filter('wicket_guest_payment_email_allowed_html', function (array $allowed_html) {
    $allowed_html['img'] = [
        'src' => true,
        'alt' => true,
        'style' => true,
    ];

    return $allowed_html;
});
```

## Token Expiry

Configured value is applied to token generation via:

- Wicket setting: `wicket_admin_settings_guest_payment_token_expiry_days`
- Filter: `wicket/wooguestpay/token_expiry_days`

```php
add_filter('wicket/wooguestpay/token_expiry_days', fn ($days) => 14);
```

## Troubleshooting

### Guest message not appearing in WooCommerce emails

1. Ensure feature is enabled:

```php
var_dump(apply_filters('wicket/wooguestpay/email_integration_enabled', false));
```

2. Ensure order status is `pending`.

3. Ensure the WooCommerce email template triggers `woocommerce_email_before_order_table`.

### Admin-sent email not using expected template

1. Confirm values are saved in **Wicket -> Settings -> Integrations -> Guest Checkout**.
2. Check for overrides via filters (`wicket_guest_payment_email_subject`, `wicket_guest_payment_email_content`).
3. If HTML is being stripped, verify `wicket_guest_payment_email_sanitize_html` is not forced to `true` by custom code.

## Related Docs

- [Configuration Quick Reference](configuration-quick-reference.md)
- [Email Template Customization](email-template-customization.md)
- [PDF Integration Configuration](pdf-integration.md)
