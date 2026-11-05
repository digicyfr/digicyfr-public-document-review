=== Digicyfr Public Document Review ===
Contributors: digicyfr
Tags: pdf, documents, editorial, accessibility
Requires at least: 7.1
Requires PHP: 8.3
Tested up to: 7.1
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Inventories saved public PDF links and keeps private attachment owner, next-review date, and note.

== Description ==

Manual bounded scan of published, password-free posts and pages. Conservative exact URL matching to existing PDF attachments; mismatches and external links remain uncertain. Private admin/editor workflow and formula-guarded CSV. No remote fetches, email, cron, PDF reading, legal-validity claim, file replacement, or public output. English and Polish runtime translations included.

== Installation ==

1. Upload the ZIP and activate it. InnoDB is required for two site-prefixed tables.
2. An administrator opens PDF Reviews and starts/continues a scan until Complete.
3. Review matched PDFs and uncertain links separately. Export only a complete scan.

== Frequently Asked Questions ==

= Does a match prove the PDF is current or legally valid? =

No. A match proves only that a saved link equals the current filtered URL of an existing Media Library PDF attachment.

= What happens on uninstall? =

Both plugin-owned tables, options, and all five protected attachment review meta keys are removed. Deactivation retains them.

== Changelog ==

= 0.1.1 =

Underline matched-report source links so they remain identifiable without color.

= 0.1.0 =

Initial saved-link inventory, private review workflow, English/Polish strings, and complete-snapshot CSV.
