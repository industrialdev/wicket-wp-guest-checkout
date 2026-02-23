# Wicket Guest Checkout - Documentation Index

Welcome to the documentation for the Wicket Guest Checkout plugin.

## Quick Start

1. Install and activate the plugin.
2. Generate a link from the order admin Guest Payment meta box.
3. Configure settings as needed in:
   - `Wicket -> Settings -> Integrations -> Guest Checkout`
4. Optionally enable email/PDF integration behavior via filters/options.

## Main Docs

- [Plugin README](../README.md)
- [Configuration Quick Reference](configuration-quick-reference.md)
- [Email Integration Configuration](email-integration.md)
- [PDF Integration Configuration](pdf-integration.md)
- [Email Template Customization](email-template-customization.md)

## What Changed in Current Docs

The docs in this folder reflect current implementation, including:

- Guest Checkout settings location under **Wicket Settings -> Integrations**.
- Configurable admin-sent guest payment email subject/body templates.
- Full HTML support for body templates.
- Current supported hooks/filters only (removed stale examples for non-existent filters).

## Helpful Entry Points

- Need placeholders + HTML template behavior: [Email Template Customization](email-template-customization.md)
- Need toggles and runtime filters: [Configuration Quick Reference](configuration-quick-reference.md)
- Need WooCommerce email message injection behavior: [Email Integration Configuration](email-integration.md)
- Need PDF invoice behavior: [PDF Integration Configuration](pdf-integration.md)

## Compatibility

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.2+

