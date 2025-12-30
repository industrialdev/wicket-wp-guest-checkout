# Plan: Implement PHPUnit 11 Tests with BrainMonkey

## Phase 1: Environment Setup
- [x] Task: Install Development Dependencies (7731fb1)
    - [x] Sub-task: Remove existing PHPUnit dependency if incompatible.
    - [x] Sub-task: Install `phpunit/phpunit:^11.0` and `brain/monkey` via Composer.
- [x] Task: Configure PHPUnit (abda561)
    - [x] Sub-task: Create/Update `phpunit.xml` with PHPUnit 11 schema.
    - [x] Sub-task: Configure test suites and coverage settings.
- [x] Task: Create Test Bootstrap (412c0b0)
    - [x] Sub-task: Create `tests/bootstrap.php` to load Composer and initialize BrainMonkey.
    - [x] Sub-task: Create a base test case class (e.g., `tests/unit/TestCase.php`) that handles BrainMonkey `setUp` and `tearDown`.
- [x] Task: Conductor - User Manual Verification 'Environment Setup' (Protocol in workflow.md)

## Phase 2: Unit Test Implementation
- [x] Task: Test WicketGuestPaymentCore - Token Generation (de769eb)
    - [x] Sub-task: Write Tests: Create `tests/unit/WicketGuestPaymentCoreTokenTest.php` and define test cases for `generate_token`.
    - [x] Sub-task: Implement Tests: Use BrainMonkey to mock `openssl_encrypt` (if wrapped) or WP authentication keys, and `update_post_meta`.
- [x] Task: Test WicketGuestPaymentCore - Token Validation (de769eb)
    - [x] Sub-task: Write Tests: Define test cases for `validate_token` (valid, expired, invalid hash).
    - [x] Sub-task: Implement Tests: Mock `get_post_meta` to return mocked token data and assert validation results.
- [x] Task: Conductor - User Manual Verification 'Unit Test Implementation' (Protocol in workflow.md)

## Phase 3: Verification & Cleanup
- [x] Task: Execute Test Suite (de769eb)
    - [x] Sub-task: Run `vendor/bin/phpunit` and ensure all tests pass.
- [x] Task: Check Coverage (de769eb)
    - [x] Sub-task: Verify code coverage meets the project standard (80%).
- [x] Task: Code Style (7aac36a)
    - [x] Sub-task: Run `composer format` and `composer lint` to ensure test files follow PSR-12.
- [x] Task: Conductor - User Manual Verification 'Verification & Cleanup' (Protocol in workflow.md)