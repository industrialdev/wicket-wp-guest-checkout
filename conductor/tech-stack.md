# Tech Stack: Wicket Guest Checkout

## Core Technologies
- **PHP:** >= 8.2 (Strict types enabled)
- **WordPress:** 6.0+
- **WooCommerce:** 10.0+

## Dependency Management & Tooling
- **Composer:** Used for managing development dependencies and autoloading.
- **PHP CS Fixer:** Ensures adherence to coding standards (PSR-12).

## Database
- **WordPress Database (MySQL/MariaDB):** Utilizes standard WordPress and WooCommerce database schemas for data persistence and meta management.

## Architecture
- **PSR-4 Autoloading:** Organized under the `Wicket\GuestPayment\` namespace.
- **Singleton Pattern:** Used for core coordinator classes.
- **WooCommerce CRUD:** High-Performance Order Storage (HPOS) compatible methods.

## Testing Framework
- **PHPUnit:** 11.x (Modern, isolated unit testing)
- **BrainMonkey:** 2.6+ (Mocking WordPress and WooCommerce functions)
- **Mockery:** Integrated with BrainMonkey for object mocking

