# Changelog

All notable changes to this plugin are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

<!-- new releases inserted below this line -->

## [1.3.33] - 2026-08-17

### Fixed
- **guest-payment:** add defensive logging around cart sync
- **guest-payment:** sync guest-added cart items into reused order


## [1.3.32] - 2026-08-14

### Fixed
- preserve manual line-item discounts in guest checkout cart rebuild


## [1.3.31] - 2026-08-13

### Fixed
- **security:** close admin-pay session escalation (F-04)


## [1.3.30] - 2026-08-11

### Fixed
- **guest-payment:** keep payment link alive for offline methods
- **guest-payment:** fail closed when guest cart diverges from order


## [1.3.29] - 2026-08-05

### Fixed
- **guest-payment:** restore cart_item_data init (root cause of fatal)
- **guest-payment:** catch Throwable in cart prep, clear partial cart

### Documentation
- add automated release process to AGENTS.md #norelease
- self-contained release automation reference #norelease
- add release automation reference #norelease


## [1.3.28] - 2026-07-09

### Added
- **ci:** auto version bump, tag, and changelog on merge to main

