# Changelog

All notable changes to Wicket Guest Checkout will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-01-09

### Added
- Initial release of Wicket Guest Checkout
- Secure token generation with AES-256-CBC encryption
- HMAC-SHA256 token validation for anti-tampering
- Guest payment flow with temporary authentication
- Admin interface for managing guest payment links
- Email notification system for payment link delivery
- Manual link generation and copying
- Link invalidation functionality
- Cart preservation with custom pricing support
- Session management and automatic cleanup
- Access restrictions for guest sessions
- Rate limiting for failed token attempts (5 per 15 minutes)
- IP-based brute force protection
- Receipt access system with 30-day validity
- Receipt email delivery
- PDF invoice plugin integration hooks
- WooCommerce Subscriptions compatibility
- HPOS (High-Performance Order Storage) compatibility
- Duplicate order prevention system
- Comprehensive logging via WooCommerce logger
- Support for both classic and block checkout
- Rewrite rules for receipt access endpoints
- Automatic session cookie management
- Admin bar hiding for guest sessions
- WordPress authentication key fallback for encryption
- Plugin activation/deactivation hooks
- Translation ready with text domain 'wicket-acc'

### Security
- AES-256-CBC encryption for all payment tokens
- HMAC validation on token retrieval
- Time-limited tokens (7-day default expiry)
- Secure cookie handling with httpOnly flag
- Rate limiting to prevent brute force attacks
- Automatic logout after payment completion
- Session data cleanup on payment or logout
- Admin area access prevention for guest sessions

### Developer Features
- PSR-12 coding standards compliance
- PHP 8.2+ type declarations
- Autoloader for all plugin classes
- Extensive filter and action hooks
- WooCommerce logger integration
- HPOS compatibility throughout
- Singleton pattern for main class
- Comprehensive inline documentation

## [Unreleased]

### Planned
- Settings page in WooCommerce admin
- Configurable token expiry time via admin
- Custom email template editor
- Multiple language support files
- Admin dashboard widget for guest payment stats
- Export guest payment data functionality
- Webhook support for external integrations
- REST API endpoints for programmatic access
- Guest payment analytics and reporting

---

[1.0.0]: https://github.com/wicket/wicket-guest-checkout/releases/tag/v1.0.0
