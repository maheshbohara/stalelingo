# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Phase 1 scaffold:
  - Docker development stack (MariaDB 11, WordPress, WP-CLI, PHP tools, Node 22, Playwright, Mailpit) and a Makefile.
  - `bin/setup.sh` builds an EN/FR/ES Polylang site with translator users and seeded posts, pages and a custom post type.
  - Main plugin file, PSR-4 autoloading, and activation that creates the `tdrift_sync`, `tdrift_snapshots` and `tdrift_events` tables through versioned `dbDelta()` migrations.
  - `tdrift_manage` capability, granted to administrators and editors, and filterable with `tdrift_capability_roles`.
  - Polylang and WPML detection (`tdrift_provider` filter), and one notice on the Plugins screens and the plugin's own screens when neither is active.
  - Tools → Translation Drift screen shell. Its assets load on that screen only.
  - `uninstall.php`, which honours the "delete data" setting on single sites and across a multisite network.
  - Plugin name: "Translation Drift – Outdated Translation Tracker for Multilingual Sites". Author: Mahesh Bohara.
  - PHPCS (WordPress, WordPress-Extra, WordPress-Docs), PHPCompatibilityWP (PHP 8.1 and newer), PHPStan level 8, PHPUnit unit and integration suites, Jest, Playwright, the Plugin Check runner, a readme validator, coverage thresholds and CI.
