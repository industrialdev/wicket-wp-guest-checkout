=== Wicket Guest Checkout ===
Contributors: wicket
Tags: woocommerce, guest checkout, payment link, guest payment, order payment
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure guest payment system for WooCommerce. Generate payment links for guests to complete orders on behalf of registered users.

== Description ==

Wicket Guest Checkout is a powerful WooCommerce extension that allows administrators to generate secure, time-limited payment links that can be shared with guests. These guests can then complete payment for orders on behalf of registered users without needing to log in or create an account.

= Key Features =

* **Secure Token Generation** - Generate encrypted, time-limited payment tokens for each order
* **Guest Payment Flow** - Guests can pay for orders without logging in or creating an account
* **Admin Management** - Easily manage guest payment links from the WooCommerce order admin
* **Email Notifications** - Automatically send payment links via email
* **Manual Link Generation** - Generate links manually and share them through any channel
* **Cart Preservation** - Original order items and pricing are preserved during guest payment
* **Session Restrictions** - Guest sessions are restricted to checkout pages only for security
* **Automatic Cleanup** - Sessions and tokens are automatically cleaned up after payment
* **Receipt Access** - Guests receive access to payment receipts after completion
* **Invoice Integration** - Works with PDF invoice plugins for seamless receipt delivery

= How It Works =

1. Admin creates or identifies a pending order for a registered user
2. Admin generates a guest payment link from the order admin panel
3. Link is sent to the guest payer via email or shared manually
4. Guest clicks the link and is temporarily authenticated
5. Guest completes payment through WooCommerce checkout
6. Order is updated and guest is automatically logged out
7. Guest receives receipt access link

= Security Features =

* AES-256-CBC encryption for payment tokens
* HMAC hash validation for anti-tampering
* Rate limiting for failed token validation attempts
* IP-based brute force protection
* Time-limited tokens (7 days default, configurable)
* Automatic session cleanup after payment
* Restricted page access during guest sessions
* Secure cart key generation

= Use Cases =

* Organizations paying for member subscriptions
* Parents paying for children's orders
* Corporate expense management
* Gift purchases
* Third-party payment services

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 8.0 or higher
* PHP 8.2 or higher
* SSL certificate (HTTPS) recommended for security

== Installation ==

= Automatic Installation =

1. Log in to your WordPress dashboard
2. Navigate to Plugins > Add New
3. Search for "Wicket Guest Checkout"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to your WordPress dashboard
3. Navigate to Plugins > Add New > Upload Plugin
4. Choose the downloaded ZIP file and click "Install Now"
5. Click "Activate Plugin"

= Configuration =

1. Ensure WooCommerce is installed and activated
2. Navigate to WooCommerce > Settings > Advanced > Guest Payment (if settings page is added)
3. Configure your desired token expiry time and other settings
4. Save changes

For enhanced security, add encryption keys to your wp-config.php file:

`define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', 'your-unique-encryption-key-here');`
`define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');`

If not defined, the plugin will use WordPress authentication keys as a fallback.

== Frequently Asked Questions ==

= Does this work with WooCommerce Subscriptions? =

Yes! The plugin is fully compatible with WooCommerce Subscriptions and can handle subscription orders.

= How long are payment links valid? =

By default, payment links are valid for 7 days. This can be configured through filters in the code.

= Can I customize the email templates? =

Yes, email templates can be customized by copying the template files to your theme directory and modifying them.

= Is this secure? =

Yes! The plugin implements multiple security layers including encryption, HMAC validation, rate limiting, and automatic session cleanup.

= What happens if a guest tries to access other pages? =

Guest sessions are restricted to checkout-related pages only. Attempts to access other pages will redirect back to the cart.

= Can I use this with my PDF invoice plugin? =

Yes! The plugin includes integration hooks for popular PDF invoice plugins like WooCommerce PDF Invoices & Packing Slips.

== Screenshots ==

1. Guest Payment meta box in order admin
2. Generate and send guest payment link
3. Manual link generation interface
4. Guest checkout flow
5. Receipt access page

== Changelog ==

= 1.0.0 - 2025-01-XX =
* Initial release
* Secure token generation and validation
* Guest payment flow implementation
* Admin interface for link management
* Email notification system
* Receipt access functionality
* Invoice integration support
* Session management and cleanup
* Rate limiting and security features

== Upgrade Notice ==

= 1.0.0 =
Initial release of Wicket Guest Checkout.

== Privacy Policy ==

This plugin stores the following data:

* Guest email addresses (for payment link delivery)
* Encrypted payment tokens (stored in order meta)
* Token creation timestamps
* User session data (temporary, deleted after payment)
* Cart data (temporary, deleted after payment)

All sensitive data is encrypted and automatically cleaned up after payment completion.

== Support ==

For support, please visit [https://github.com/wicket/wicket-guest-checkout/issues](https://github.com/wicket/wicket-guest-checkout/issues)

== Development ==

This plugin is developed on GitHub. For development information and to contribute, visit [https://github.com/wicket/wicket-guest-checkout](https://github.com/wicket/wicket-guest-checkout)
