# Specification: Implement PHPUnit 11 Tests with BrainMonkey

## 1. Overview
The goal of this track is to establish a modern, isolated unit testing environment for the `Wicket Guest Checkout` plugin. We will transition from any legacy or full-integration test setups to a pure unit testing approach using **PHPUnit 11** and **BrainMonkey**. This allows us to test plugin logic in isolation by mocking WordPress and WooCommerce functions, resulting in faster and more reliable tests.

## 2. Requirements

### 2.1 Dependencies
- **PHPUnit:** Version 11.x (Strict dependency)
- **BrainMonkey:** Latest compatible version for mocking WordPress functions.
- **Mockery:** (Usually pulled in by BrainMonkey) for general object mocking.

### 2.2 Configuration
- **phpunit.xml:** Must be configured to:
    - Point to the `tests/unit` directory.
    - Use the custom `tests/bootstrap.php`.
    - Enforce high code coverage (aiming for project standard).
- **Bootstrap:** A new `tests/bootstrap.php` must be created to:
    - Require the Composer autoloader.
    - Initialize `Brain\Monkey`.
    - Define any essential WP constants usually needed by the code under test (if strictly necessary and safe).

### 2.3 Test Implementation
- **Target Class:** `WicketGuestPaymentCore` (and others as time permits, but Core is the priority).
- **Mocking Strategy:**
    - Use `Brain\Monkey\Functions\expect()` to mock WP functions like `get_post_meta`, `update_post_meta`, `get_option`, etc.
    - Use `Mockery` to mock WooCommerce objects (e.g., `WC_Order`, `WC_Cart`).
- **Tests to Implement:**
    - Token generation logic (encryption/hashing).
    - Token validation logic (expiry checks, hash verification).

## 3. Non-Functional Requirements
- **Isolation:** Tests must run without a database connection or a running WordPress instance.
- **Performance:** Tests should execute in seconds.
- **Compatibility:** Ensure the setup works with PHP 8.2+.

## 4. Out of Scope
- Integration tests (using `WP_UnitTestCase`).
- UI/Browser testing.
