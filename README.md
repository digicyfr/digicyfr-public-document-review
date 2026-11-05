# Digicyfr Public Document Review

A free GPL WordPress plugin that inventories PDF links already saved in published posts and pages. It links a proven Media Library PDF to its public source pages and gives staff a private owner, next-review date, and note. It does **not** read a PDF, verify its legal validity, test download availability, replace a file, send email, or add public output.

## Install and use

Upload the 0.1.0 ZIP at **Plugins → Add New → Upload Plugin**. Tested with WordPress 7.1, PHP 8.3.33 and MariaDB 11.4.13 using InnoDB. The plugin requires WordPress 7.1+, PHP 8.3+, and InnoDB for its two site-prefixed tables.

1. Open **PDF Reviews** in the WordPress admin. An administrator starts a scan and presses **Continue one batch** until it is complete. Each request handles at most 25 public posts/pages. You may leave the page and resume later; **Cancel scan** preserves the previous complete snapshot.
2. The complete inventory groups proven PDF attachments by Media Library item and lists their referencing pages. Filter by Due today, Overdue, Upcoming, or Unscheduled. Administrators can inspect an **Uncertain links** filter with mismatched File block IDs, attachment-page links, unproven local/external URLs, and competing hints. These rows never gain review controls.
3. Staff with `upload_files` and `edit_post` for a matched PDF can enter an eligible owner user ID, strict site-local next-review date, and private note. **Mark reviewed** records UTC reviewer/time and requires a future next date or an explicit Unscheduled choice. The note is current state, not an event history. Editors see only matched attachments they can edit; only administrators scan and export.
4. Administrators can export **only the active complete snapshot** as UTF-8 CSV. Its fixed columns are `scan_completed_at_utc,source_post_id,source_title,source_url,pdf_url,attachment_id,match_state,owner_id,owner_name,due_date,review_status,last_reviewed_at_utc,note`. Every text cell is guarded against spreadsheet formulas with a leading apostrophe where needed. The CSV is built in bounded chunks into a temporary local stream and sent only after all reads and writes succeed.

## Discovery contract

The scan reads saved `post_content` of published, password-free `post` and `page` objects, including paragraph/classic HTML, nested blocks, and File blocks. It uses WordPress's HTML Tag Processor to count saved `<a href>` occurrences once. Two text/download anchors to one URL become one source row with `occurrence_count=2`. It recursively reads File block ID/href hints without counting an attribute again when saved anchors exist. It never renders blocks, executes shortcodes, follows URLs, or scans theme templates, widgets, dynamic output, page-builder data outside `post_content`, or later public filters.

Only HTTP(S) URLs are considered. Root-relative and protocol-relative links resolve against the site's origin; scheme/host are lowercased and fragments removed. Path case, percent encoding, and query strings are preserved. A URL is a candidate when its path ends `.pdf` (case-insensitive), or a File block identifies an existing PDF attachment even if the URL has no PDF suffix. A link is **matched** only when a valid PDF attachment ID and its current filtered direct URL equal the normalized saved link. `attachment_url_to_postid()` is a hint, not proof. A File block ID with a different URL is uncertain, as is an attachment-page link. No local-file existence check is used, so offloaded PDFs are not falsely declared unavailable.

Each run uses a start-time high-water post ID and an ascending ID cursor. Publication and edits are observed when the cursor reaches each post, so this is not an immutable snapshot. A changed, unpublished, password-protected, or deleted source appears stale/deleted in the saved report; rescan for a current inventory. The report compares the saved source timestamp, content fingerprint, title and permalink, so edits within the same second are still marked stale. New uploads and source edits after completion are not included until a new scan.

## Limits, privacy, and retention

One Continue request handles at most 25 posts. A source over 1 MiB, more than 300 saved link occurrences, a URL over 2048 bytes after resolution, more than 10 competing File IDs for one link, parse failure, or database failure marks the run **incomplete**. A 50,000 stored-link limit stops the run and explicitly leaves later source IDs unscanned. An incomplete/active/cancelled run is never offered as a complete CSV. The previous complete snapshot remains available until a new scan finishes without skips.

Two site-prefixed InnoDB tables store run state and link rows; a non-autoloaded option mirrors scan state and a second option points to the active complete run. Review state is protected attachment metadata (`_dpdr_owner_id`, `_dpdr_due`, `_dpdr_note`, `_dpdr_reviewed_at_utc`, `_dpdr_reviewed_by`). Administrators see uncertain links; editors see only matched attachments they can edit. Scan actions, review saves, and CSV use WordPress nonces and capability checks. Deactivation retains data; **uninstall removes both tables, options, and all five review meta keys permanently**. Export a needed report first.

Version 0.1.0 is a manual ZIP update from Digicyfr; the unique `Update URI` prevents an unrelated directory update. No automatic update endpoint exists yet.

Source: [github.com/digicyfr/digicyfr-public-document-review](https://github.com/digicyfr/digicyfr-public-document-review). For ordinary support use [Issues](https://github.com/digicyfr/digicyfr-public-document-review/issues); for vulnerabilities use [private security reporting](SECURITY.md).

## Development

From the repository root:

```bash
python3 scripts/release.py
python3 scripts/release.py --verify dist/digicyfr-public-document-review-0.1.0.zip
(cd dist && sha256sum -c digicyfr-public-document-review-0.1.0.zip.sha256)
bash tests/run-wordpress-integration.sh dist/digicyfr-public-document-review-0.1.0.zip
```

License: GPL-2.0-or-later; see [LICENSE](LICENSE).
