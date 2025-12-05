# Wicket Guest Checkout - Documentation Index

Welcome to the complete documentation for the Wicket Guest Checkout plugin. This guide provides comprehensive information for administrators, developers, and users.

## 🚀 Quick Start

### New to the Plugin?
Start here for basic setup and understanding:

- **[README.md](README.md)** - Overview, installation, and basic usage
- **[Configuration Quick Reference](configuration-quick-reference.md)** - All configuration options at a glance

### First-Time Setup
1. [Install the plugin](../README.md#installation)
2. [Review basic configuration](../README.md#configuration)
3. [Enable email integration](email-integration.md) if needed
4. [Enable PDF integration](pdf-integration.md) if needed

## 📚 Complete Documentation

### **Core Documentation**

#### [📖 README.md](README.md)
Main plugin documentation with overview, installation, and basic configuration.

- **What you'll learn:** Plugin overview, core features, installation steps
- **Best for:** Everyone starting with the plugin

#### [⚙️ Configuration Quick Reference](configuration-quick-reference.md)
Complete reference of all filters, constants, and configuration options.

- **What you'll learn:** All available configuration options, code examples, conditional setup
- **Best for:** Developers and advanced administrators

---

### **Integration Guides**

#### [📧 Email Integration](email-integration.md)
Configure automatic guest payment links in WooCommerce emails.

- **What you'll learn:** Enable/disable email integration, conditional activation, troubleshooting
- **Best for:** Site administrators, developers
- **Features covered:** WooCommerce email hooks, message customization, security

#### [📄 PDF Integration](pdf-integration.md)
Configure automatic guest payment links in PDF invoices.

- **What you'll learn:** PDF plugin compatibility, conditional activation, styling options
- **Best for:** Site administrators, developers
- **Features covered:** PDF invoice hooks, styling, compatibility

---

### **Security & Advanced Topics**

#### [🔒 Security Guide](security.md) *(Coming Soon)*
Comprehensive security considerations and best practices.

- **What you'll learn:** Token security, encryption, rate limiting, audit recommendations
- **Best for:** Security administrators, developers
- **Topics:** AES-256 encryption, HMAC validation, IP rate limiting

#### [🛠️ Advanced Configuration](advanced-config.md) *(Coming Soon)*
Advanced customization options and developer guide.

- **What you'll learn:** Custom hooks, API integration, advanced use cases
- **Best for:** Developers, technical administrators
- **Topics:** Custom payment flows, API endpoints, third-party integration

---

### **User & Administration Guides**

#### [👤 Admin Usage Guide](admin-usage.md) *(Coming Soon)*
Complete guide for administrators using the plugin interface.

- **What you'll learn:** Order management, link generation, email sending, troubleshooting
- **Best for:** Site administrators, store managers
- **Features covered:** Admin meta box, bulk operations, order management

---

### **Developer Resources**

#### [🔧 API Reference](api-reference.md) *(Coming Soon)*
Complete API documentation for developers.

- **What you'll learn:** Available functions, methods, parameters, return values
- **Best for:** Plugin developers, custom integration
- **Topics:** Core functions, utility methods, data structures

#### [🪝 Hook Reference](hook-reference.md) *(Coming Soon)*
Complete list of available actions and filters.

- **What you'll learn:** All available hooks, parameters, usage examples
- **Best for:** Plugin developers, theme developers
- **Topics:** Actions, filters, priority settings, arguments

#### [🗄️ Database Schema](database-schema.md) *(Coming Soon)*
Database structure and data storage documentation.

- **What you'll learn:** Meta keys, data formats, relationships
- **Best for:** Developers, database administrators
- **Topics:** Order meta, user meta, transients, data flow

---

### **Support & Troubleshooting**

#### [🔧 Troubleshooting Guide](troubleshooting.md) *(Coming Soon)*
Solutions to common issues and problems.

- **What you'll learn:** Common problems, solutions, debugging steps
- **Best for:** All users experiencing issues
- **Topics:** Link problems, payment issues, configuration errors

#### [🐛 Debug Mode](debug-mode.md) *(Coming Soon)*
How to enable and use debugging features.

- **What you'll learn:** Debug configuration, log analysis, troubleshooting tools
- **Best for:** Developers, technical administrators
- **Topics:** WordPress debug, WooCommerce logs, custom logging

---

## 🎯 Quick Navigation by Role

### **👨‍💼 Site Administrators**
- [README.md](README.md) - Plugin overview and installation
- [Email Integration](email-integration.md) - Set up automatic email links
- [PDF Integration](pdf-integration.md) - Set up automatic PDF links
- [Admin Usage Guide](admin-usage.md) - How to use admin interface *(Coming Soon)*

### **👨‍💻 Developers**
- [Configuration Quick Reference](configuration-quick-reference.md) - All configuration options
- [Email Integration](email-integration.md) - Advanced email integration
- [PDF Integration](pdf-integration.md) - Advanced PDF integration
- [API Reference](api-reference.md) - Function documentation *(Coming Soon)*
- [Hook Reference](hook-reference.md) - Complete hook list *(Coming Soon)*
- [Advanced Configuration](advanced-config.md) - Custom development *(Coming Soon)*

### **🔒 Security Administrators**
- [Security Guide](security.md) - Security best practices *(Coming Soon)*
- [Configuration Quick Reference](configuration-quick-reference.md) - Security-related filters
- [Troubleshooting Guide](troubleshooting.md) - Security issues *(Coming Soon)*

---

## 🏗️ Documentation Structure

```
docs/
├── index.md                           # This file - Documentation index
├── README.md                           # Plugin overview and quick start
├── configuration-quick-reference.md    # All configuration options
├── email-integration.md               # Email integration guide
├── pdf-integration.md                 # PDF integration guide
├── security.md                        # Security considerations (Coming Soon)
├── admin-usage.md                     # Admin interface guide (Coming Soon)
├── api-reference.md                   # Developer API (Coming Soon)
├── hook-reference.md                  # Complete hook list (Coming Soon)
├── database-schema.md                 # Data storage structure (Coming Soon)
├── advanced-config.md                 # Advanced customizations (Coming Soon)
├── troubleshooting.md                 # Common issues (Coming Soon)
└── debug-mode.md                      # Debug configuration (Coming Soon)
```

---

## 📋 Popular Configuration Examples

### **Enable Email Integration**
```php
// Add to functions.php
add_filter('wicket/wooguestpay/email_integration_enabled', '__return_true');
```
*Learn more: [Email Integration](email-integration.md)*

### **Enable PDF Integration**
```php
// Add to functions.php
add_filter('wicket/wooguestpay/pdf_integration_enabled', '__return_true');
```
*Learn more: [PDF Integration](pdf-integration.md)*

### **Conditional Activation**
```php
// Enable only for orders over $100
add_filter('wicket/wooguestpay/email_integration_enabled', function($enabled, $order) {
    return $order && $order->get_total() > 100;
}, 10, 2);
```
*Learn more: [Configuration Quick Reference](configuration-quick-reference.md)*

### **Custom Token Expiry**
```php
// Extend token validity to 14 days
add_filter('wicket/wooguestpay/token_expiry_days', function($days) {
    return 14;
});
```
*Learn more: [Configuration Quick Reference](configuration-quick-reference.md)*

---

## 🎯 Getting Started Checklist

### **Basic Setup**
- [ ] Install and activate plugin
- [ ] Review [README.md](README.md) for basic configuration
- [ ] Test basic link generation from order admin

### **Integration Setup**
- [ ] Decide if you need email integration
- [ ] Configure [Email Integration](email-integration.md) if required
- [ ] Configure [PDF Integration](pdf-integration.md) if required

### **Advanced Configuration**
- [ ] Review [Configuration Quick Reference](configuration-quick-reference.md) for all options
- [ ] Set up custom conditions if needed
- [ ] Configure security settings

### **Testing & Deployment**
- [ ] Test complete guest payment flow
- [ ] Verify email delivery (if enabled)
- [ ] Check PDF generation (if enabled)
- [ ] Monitor logs for any issues

---

## 🔗 Related Resources

### **WordPress & WooCommerce**
- [WooCommerce Documentation](https://woocommerce.com/documentation/)
- [WordPress Codex](https://codex.wordpress.org/)
- [WordPress Plugin Developer Handbook](https://developer.wordpress.org/plugins/)

### **Security & Best Practices**
- [WordPress Security Best Practices](https://wordpress.org/documentation/article/hardening-wordpress/)
- [WooCommerce Security Guide](https://woocommerce.com/document/woocommerce-security/)
- [PHP Security Guidelines](https://www.php-fig.org/psr/psr-12/#6-security)

### **Development Tools**
- [WordPress Plugin Boilerplate](https://github.com/DevinVinson/WordPress-Plugin-Boilerplate)
- [WooCommerce CRUD Documentation](https://woocommerce.github.io/code-reference/)
- [PHP Standards](https://www.php-fig.org/psr/psr-12/)

---

## 📞 Getting Help

### **Documentation Help**
- **Can't find what you need?** Check the [Configuration Quick Reference](configuration-quick-reference.md)
- **Need more examples?** Review the [Email Integration](email-integration.md) and [PDF Integration](pdf-integration.md) guides
- **Still stuck?** See the [Troubleshooting Guide](troubleshooting.md) *(Coming Soon)*

### **Community Support**
- **Issues and Bug Reports:** Check plugin repository
- **Feature Requests:** Check plugin repository
- **General Questions:** Check plugin repository

### **Debug Information**
Enable debug mode to get detailed information:
```php
define('WICKET_GUEST_PAYMENT_DEBUG', true);
```

---

**Last Updated:** December 2024
**Plugin Version:** 1.0.0
**Documentation Version:** 1.0.0
**Compatible with:** WordPress 6.0+, WooCommerce 8.0+, PHP 8.2+