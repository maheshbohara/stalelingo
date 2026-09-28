=== Translation Drift – Outdated Translation Tracker for Multilingual Sites ===
Tags: multilingual, translation, translation management, localization, content audit
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
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

Translation Drift stores the ID of the user who marked a translation as up to date, and of the user whose action changed a translation's status. This data stays in your database. The plugin registers personal data exporters and an eraser (which removes the user from these records) and suggests text for your privacy policy. If you turn on email digests, the translators' email addresses are used to send them.

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

= How do email digests work? =

In **Settings → Translation Drift**, assign translators to each language and choose a daily or weekly digest. Each translator gets a list of the outdated translations in their languages, with links to edit them. Addresses in "Also send the digest to" get every language. You can also email translators as soon as a translation of chosen post types goes out of date. Emails are sent with WordPress's own mail function, so an SMTP plugin applies to them too.

= Which WP-CLI commands are there? =

`wp translation-drift report` lists translations and their status (as a table, CSV or JSON), `wp translation-drift mark-synced` marks translations as up to date by ID or all at once, `wp translation-drift baseline` builds the baseline now (add `--dry-run` to preview it), and `wp translation-drift recalc` recalculates every status. Run `wp help translation-drift` for details.

= Does it work on multisite? =

Yes. Activate it on single sites, or network-activate it to set up every site, including sites created later. Each site has its own settings and data.

= What happens to my data if I delete the plugin? =

Nothing is deleted unless you turn on "Delete data on uninstall" in the settings. With it on, deleting the plugin removes its tables, settings and capability on that site.

= Where is the source code of the JavaScript and CSS? =

The `src` folder of the plugin holds the readable TypeScript and SCSS source of everything in `build`, which is compiled with `@wordpress/scripts`. The PHP code in `includes` is not compiled.

== Screenshots ==

1. The dashboard with outdated and missing translations by language.
2. The field-by-field diff of what changed in the source.
3. The editor panel on an outdated translation.
4. Status badges in the posts list.
5. The settings screen.

== Changelog ==

= 1.0.0 =
* First public release.
* Tracks title, content (block by block), excerpt, slug, featured image, custom fields, ACF fields and blocks, and Elementor text; formatting-only and styling-only edits are ignored unless strict mode is on.
* Works with Polylang (free and Pro) and WPML.
* Dashboard under Tools with summary counts, filters, bulk "Mark as up to date" and CSV export; field-by-field diffs.
* Block editor panel and classic editor metabox, a status column in post lists, and an admin bar counter.
* Daily or weekly email digests per translator, and optional immediate emails.
* REST API (`tdrift/v1`) and WP-CLI commands (`wp translation-drift`).
* Personal data exporters and eraser; multisite support.

== Upgrade Notice ==

= 1.0.0 =
First public release.
