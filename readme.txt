=== Translation Drift – Outdated Translation Tracker for Multilingual Sites ===
Tags: multilingual, translation, translation management, localization, content audit
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know which translations went out of date when the source post changed, see exactly what changed, and clear the backlog. Works with Polylang and WPML.

== Description ==

When the source version of a post changes, its translations silently go out of date. Translation Drift fingerprints the source content every time a translation is marked as up to date. When the source changes, it flags each affected translation as **outdated** and shows exactly which fields changed.

Translation Drift works with **Polylang** (including the free version) and **WPML**. One of them must be active.

= What it tracks =

* Title, content, excerpt, and optionally the slug and featured image.
* Custom fields you choose, including ACF fields set to be translated.
* Visible Elementor text (headings, text editors, buttons). Styling-only changes are ignored.
* Formatting-only edits, such as whitespace or block attribute changes, are ignored unless you turn on strict mode.

= Where you see it =

* A dashboard under **Tools → Translation Drift**, with a matrix of posts and languages, filters, bulk actions and CSV export.
* A status column with one badge per language in your post lists.
* A panel in the block editor (and a metabox in the classic editor) with a field-by-field diff and a **Mark as up to date** button.
* An admin bar counter, and daily or weekly email digests for each translator.
* WP-CLI commands for reports and bulk updates.

Translation Drift is completely free. There is no paid version, no license key and no tracking, and it makes no external requests.

= Privacy =

Translation Drift stores the ID of the user who marked a translation as up to date. This data stays in your database. The plugin registers a personal data exporter and eraser and suggests text for your privacy policy.

== Installation ==

1. Install and activate Polylang or WPML, and set up your languages.
2. Install and activate Translation Drift.
3. Go to **Settings → Translation Drift** and choose the post types and fields to track.
4. Translation Drift builds a baseline in the background and treats every existing translation as up to date. After that, any change to a source post flags its translations.

== Frequently Asked Questions ==

= Which multilingual plugins are supported? =

Polylang (free and Pro) and WPML. Translation Drift shows a notice on the Plugins screen if neither is active.

= Why are all my translations "up to date" right after activation? =

Translation Drift can't know what changed before it was installed, so the first baseline treats every existing translation as current. Drift is tracked from that point on.

= Does it slow down saving posts? =

No. Drift is recalculated in the background after the save, using Action Scheduler when another plugin provides it, or WP-Cron otherwise.

= Does it translate content? =

No. Translation Drift tells you what needs updating. You or your translators update the translation as usual.

= Which language is the source with Polylang? =

Polylang treats translations as equals, so Translation Drift uses the source language from its settings, which defaults to Polylang's default language. You can override it for a single post.

== Screenshots ==

1. The dashboard with outdated and missing translations by language.
2. The field-by-field diff of what changed in the source.
3. The editor panel on an outdated translation.
4. Status badges in the posts list.
5. The settings screen.

== Changelog ==

= 0.1.0 =
* Initial development release: plugin scaffold, database tables, and multilingual plugin detection.

== Upgrade Notice ==

= 0.1.0 =
First development release.
