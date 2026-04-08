---
title: "Guest Checkout Settings"
audience: [implementer, support]
wp_admin_path: "Wicket → Settings → Integrations → Guest Checkout"
php_class: Wicket\GuestPayment\WicketGuestPaymentConfig
db_option_prefix: wicket_settings[wicket_admin_settings_guest_payment_]
source_files: ["src/WicketGuestPaymentConfig.php"]
---

# Guest Checkout Settings

Configures guest payment link behaviour and the email template sent to payers. Found at **Wicket → Settings → Integrations → Guest Checkout**.

## Token Expiry (days)

Number of days before generated guest payment links expire.

| | |
|---|---|
| Option key | `wicket_settings[wicket_admin_settings_guest_payment_token_expiry_days]` |
| PHP access | `wicket_get_option('wicket_admin_settings_guest_payment_token_expiry_days')` |
| Filter | `wicket/wooguestpay/token_expiry_days` |
| Default | `7` |

## Enable "Pay for Customer Now"

Allow staff to open the admin-assisted checkout flow that lets them enter payment details on behalf of customers.

| | |
|---|---|
| Option key | `wicket_settings[wicket_admin_settings_guest_payment_enable_admin_pay]` |
| PHP access | `wicket_get_option('wicket_admin_settings_guest_payment_enable_admin_pay')` |
| Default | `true` |

## Email Subject Template

Subject line for the admin-sent guest payment email. Supports placeholders.

| | |
|---|---|
| Option key | `wicket_settings[wicket_admin_settings_guest_payment_email_subject_template]` |
| PHP access | `wicket_get_option('wicket_admin_settings_guest_payment_email_subject_template')` |
| Default | `Payment Request for {site_name} Subscription` |

**Available placeholders:** `{site_name}`, `{member_name}`, `{order_number}`, `{order_total}`, `{expiry_date}`.

## Email Body Template

Full HTML body for the admin-sent guest payment email. No auto-paragraph formatting is applied.

| | |
|---|---|
| Option key | `wicket_settings[wicket_admin_settings_guest_payment_email_body_template]` |
| PHP access | `wicket_get_option('wicket_admin_settings_guest_payment_email_body_template')` |
| Default | _(see default template in the admin UI)_ |

**Available placeholders:** `{site_name}`, `{member_name}`, `{order_number}`, `{order_total}`, `{payment_link}`, `{payment_url}`, `{expiry_date}`, `{subscription_details}`.

- `{payment_link}` renders a full `<a>` element — do not use it inside an `href` attribute.
- Upload images via the Media Library and reference them by URL.

## Integration Toggles

The email and PDF integration toggles are not stored as Wicket Settings options. They are runtime filters:

| Feature | Filter | Default |
|---|---|---|
| WooCommerce email guest payment message | `wicket/wooguestpay/email_integration_enabled` | `false` |
| PDF invoice guest payment message | `wicket/wooguestpay/pdf_integration_enabled` | `true` |

Legacy standalone options (`wicket_guest_payment_enable_email_integration`, `wicket_guest_payment_enable_pdf_integration`) are also read for backward compatibility.

## Placeholders Reference

| Placeholder | Output |
|---|---|
| `{site_name}` | Site title from `get_bloginfo('name')` |
| `{member_name}` | Customer name from the order |
| `{order_number}` | WooCommerce order number |
| `{order_total}` | Order total with currency symbol |
| `{payment_link}` | Full `<a>` element with payment URL |
| `{payment_url}` | Raw payment URL |
| `{expiry_date}` | Formatted token expiry date |
| `{subscription_details}` | HTML snippet for subscription info (when applicable) |

## Documentation Links

- [Email Integration](../guides/email-integration.md) — Enable and configure guest payment emails
- [Email Template Customization](../guides/email-template-customization.md) — Placeholders, HTML templates, hook-based customization
- [PDF Integration](../engineering/pdf-integration.md) — Hooks, token expiry, troubleshooting
- [Configuration Quick Reference](../engineering/configuration-quick-reference.md) — All filters, option keys, constants