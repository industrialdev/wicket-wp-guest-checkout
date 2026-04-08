# Email Template Customization

This guide covers customization of the **admin-sent guest payment email** (the email sent from order admin when generating/sending a guest payment link).

## Settings Location

- **Wicket -> Settings -> Integrations -> Guest Checkout**
- Direct URL: `/wp-admin/admin.php?page=wicket-settings&tab=integrations&section=guest-checkout`

## Fields

- **Email Subject Template**
- **Email Body Template** (full HTML)

## Placeholders

You can use these placeholders in both subject/body templates:

- `<code>{site_name}</code>`
- `<code>{member_name}</code>`
- `<code>{order_number}</code>`
- `<code>{order_total}</code>`
- `<code>{payment_link}</code>`
- `<code>{payment_url}</code>`
- `<code>{expiry_date}</code>`
- `<code>{subscription_details}</code>`

## HTML Behavior

- Body template is rendered as full HTML.
- No automatic paragraph conversion is applied.
- `<code>{payment_link}</code>` returns a ready-to-use `<a>` element.
- `<code>{subscription_details}</code>` may include HTML list markup.

## Using Images

1. Upload image in **Media Library** (`/wp-admin/upload.php`).
2. Copy the image URL.
3. Reference it in template HTML.

Example:

```html
<img src="{Image-URL}" alt="Logo" style="max-width:200px;height:auto;">
```

Replace `<code>{Image-URL}</code>` with your actual media URL.

## Example Body Template

```html
<p>Hello,</p>

<p>
You have received a request to complete payment on behalf of {member_name}.<br>
Order Number: {order_number}<br>
Order Total: {order_total}
</p>

<p>Please use the secure link below to complete the payment:<br>
{payment_link}</p>

<p>This payment link is valid until {expiry_date}.</p>

<p>Sincerely,<br>
{site_name}</p>
```

## Hook-Based Customization

### Subject

```php
add_filter(
    'wicket_guest_payment_email_subject',
    function ($subject, $order, $token, $placeholders, $recipient_email, $user_data) {
        return '[Payment Request] ' . $subject;
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
        return '<div style="font-family:Arial,sans-serif;">' . $html . '</div>';
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

## Optional Sanitization

Sanitization is disabled by default to allow full HTML control.

```php
add_filter('wicket_guest_payment_email_sanitize_html', '__return_true');
```

Customize allowed tags:

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
