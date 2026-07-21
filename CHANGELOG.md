# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Walking-skeleton plugin scaffold: main plugin file with a PHP 8.5 requirement guard, a hand-written PSR-4 autoloader, and a `Plugin` singleton bootstrap.
- Unauthenticated `GET /kntnt-extractor/v1/status` endpoint returning the REST contract's API version (`{ "api_version": 1 }`), separate from the plugin release version.
- A `Config` seam that resolves a value from a constant, overridable by a filter (the filter wins).
- WordPress Playground integration-test harness dispatching `GET /status` through the live REST server, plus the Composer `gate` (phpcs, PHPStan, integration suite).
- Authorized `GET /kntnt-extractor/v1/files` endpoint returning the recursive file Manifest (`path`, `size`, `mtime`) from the WordPress installation root downward, with no categorisation of what any file is for. The listing is delivered complete but paged through an opaque, path-ordered cursor the caller loops over to exhaustion, and the page size is a `Config` knob (`files_page_size`).

## [0.1.0] – 2026-07-20

### Added

- Initial release.

[Unreleased]: https://github.com/Kntnt/kntnt-extractor/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.1.0
