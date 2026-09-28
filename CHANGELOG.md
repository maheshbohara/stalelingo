# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Phase 5 React UI:
  - REST API `tdrift/v1`: `status` (filters, sorting, pagination), `status/summary`, `group/{id}`, `diff/{translation_id}`, `mark-synced` (single or bulk) and `baseline`, each with a schema and a permission check.
  - Tools → Translation Drift dashboard built on DataViews: summary counts by status, language and post type; a table of posts × languages with filters for status, language, post type, author, translator and last change; search; a bulk "Mark as up to date" action; CSV export; and a "Build baseline" prompt with progress.
  - Diff modal showing what changed in the source, field by field, with links to open the source and the translation.
  - Block editor panel: a translation's status with an inline diff and "Mark as up to date", and the affected translations on a source.
- Phase 4 admin UI:
  - Settings screen: post types, fields, extra custom fields, ACF and Elementor, source language, strict mode, auto-clear, translators per language, email digest options, immediate-email post types, delete data on uninstall, and a Build baseline tool.
  - Posts list: a translation status column with a labelled badge per language, a status filter, and a bulk "Mark translations as up to date" action.
  - Classic editor metabox with status, changed fields and "Mark as up to date".
  - Admin bar counter of outdated translations.
  - Strict mode can be switched on or off without flagging translations.

- Phase 3 integrations:
  - WPML adapter built on WPML's public filters and actions, with WPML's add-translation link for missing translations.
  - ACF fields: fields set to "translate" or "copy once" (WPML, Polylang Pro) are tracked, and text fields when no preference is set. Only text values count, including inside groups, repeaters and flexible content.
  - ACF blocks in any namespace: text fields in block data count and layout fields don't.
  - Elementor: visible widget text is tracked, and style-only changes are ignored.
  - Dev tooling: a real-site WPML validation script, plus ACF and Elementor seed content.

- Phase 2 domain core:
  - Provider interface and Polylang adapter: languages, groups, source resolution with a per-group override, and edit or create links.
  - Block-aware normalization (one line per block, visible text only unless strict mode is on), versioned SHA-256 hashing, drift evaluation and the status model (`in_sync`, `outdated`, `missing`, `untracked`).
  - Repositories for sync points, compressed and capped snapshots, and events, with daily pruning.
  - Sync service ("mark as up to date"), drift recalculation queued on source save (Action Scheduler or WP-Cron, debounced), and a batched, idempotent baseline job that is queued on activation.
  - Translations get a sync point when created. Optional auto-clear on translation save. Deletions and language changes are handled.
  - Hooks: `tdrift_tracked_fields`, `tdrift_tracked_meta_keys`, `tdrift_tracked_post_types`, `tdrift_normalize_value`, `tdrift_block_text_attributes`, `tdrift_block_text`, `tdrift_is_material_change`, `tdrift_source_language`, `tdrift_drift_detected`, `tdrift_marked_synced`, `tdrift_can_mark_synced`, `tdrift_batch_size` and the snapshot and event limits.
  - Dev setup builds the baseline and edits some sources, so the site starts with in-sync, outdated and missing translations.

### Fixed
- Diffs keep working on sites that limit post revisions: a revision is snapshotted before WordPress deletes it.
- Saving settings for the first time no longer mangles list values.
- Inline HTML tags no longer insert spaces into normalized text.
- The daily maintenance job is scheduled on sites that activated before it existed.
- Deactivation and uninstall now clear queued jobs that have arguments (`wp_unschedule_hook()`).

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
