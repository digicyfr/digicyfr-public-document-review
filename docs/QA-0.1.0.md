# QA — 0.1.0

## Candidate

- ZIP: `dist/digicyfr-public-document-review-0.1.0.zip`; the byte-identical ZIP is published as a GitHub prerelease, not yet as a stable Digicyfr site download.
- SHA-256: `933b1366e53e50ff13b2490fac4266174968cfa890b0435dde591aabf776af78`
- `tools/verify_zip.py --against-source`: all 15 allowlisted files match source byte for byte. Tests, compiler, release manifest and development docs are excluded.
- Tested stack: WordPress 7.1, PHP 8.3.33, MariaDB 11.4.13, InnoDB plugin tables. These are the tested versions, not a claim about every newer version.

## Automated gates

- PHP lint and WordPress Coding Standards on runtime PHP: passed with no findings.
- Disposable exact-ZIP install, activation, deactivation and reactivation: passed. Activation created two InnoDB tables.
- Real WordPress exact-ZIP integration: passed. It checks Polish catalog loading, Warsaw site-local dates across spring DST, URL normalization and unsafe schemes, PDF File block with two saved anchors counted once with two occurrences, an offloaded URL that matches without a local file, overlong attribute and expanded root-relative URLs marked incomplete, 301-link source and 50,000-link run caps, a clean first snapshot, post-commit pointer repair, owner permissions and literal backslash retention, review date and UTC stamp, CSV formula guard, 26 additional posts across bounded batches with a persisted resume cursor, paragraph/classic/nested saved links, mismatch/attachment-page/ambiguous/external/removed states, excluded draft/private/password sources, stale/deleted source labels, injected report/batch/remaining SQL failures, oversized-source incomplete status with previous complete report retained, post-commit cancellation read error, deactivation retention and uninstall cleanup.
- Plugin Check on the runtime-identical earlier ZIP: one `plugin_updater_detected` ERROR because the deliberate Digicyfr `Update URI` is incompatible with WordPress.org hosting. This ZIP is for manual self-hosted distribution. One `load_plugin_textdomainFound` WARNING reflects the bundled Polish catalog outside WordPress.org. No other findings remained.

## Browser and accessibility

- Root's Chromium check on the runtime-identical earlier ZIP passed: keyboard Start and Continue, matched Media Library PDF group, save of a 2027-12-01 review date, and CSV download containing the matched row, date and source. At 390px, the admin page had no horizontal overflow. The disposable site was removed.
- The review form uses explicit labels, visible table headers, standard buttons and nonce-protected POST actions. Its date field clears the Unscheduled choice when edited; the redirected result notice receives focus. These semantics were source-reviewed and the primary keyboard path was exercised in Chromium. A screen-reader session was not run.

## Post-tag browser check on the exact GitHub ZIP

On 2026-11-05, the public `v0.1.0` ZIP and sidecar were downloaded from GitHub and passed `sha256sum --check` at the hash above. It installed, activated, deactivated and reactivated on a new disposable WordPress 7.1/PHP 8.3.33/MariaDB 11.4.13 site with InnoDB tables. Chromium 156.0.8078.4 exercised the final ZIP with synthetic PDF, public-page and external-link fixtures:

- Started and completed the admin scan in one bounded batch. The real local PDF URL returned HTTP 200 with `application/pdf`; its Media Library attachment appeared in the matched report. [Complete scan](evidence/0.1.0/complete-scan.png).
- The unmatched external PDF appeared only in the administrator's uncertain-link view with the label **Unmatched external PDF link**. [Uncertain link](evidence/0.1.0/uncertain-link.png).
- Saved editor owner ID `2`, review date `2027-12-01` and a private note through the browser form. The redirected success notice received focus, the owner/date appeared in the report, and the complete CSV download contained the matched source and date. At a 390px viewport the document width was 390px, with no browser page errors. [Saved review at 390px](evidence/0.1.0/saved-review-390px.png).
- Changed the saved source content while restoring its WordPress modified timestamp to the exact scan timestamp. Its content SHA-256 changed, and the admin report showed **Changed source — rescan needed** while retaining the previous complete inventory. [Same-second stale source](evidence/0.1.0/same-second-stale.png).
- Ran a new scan from the browser. The source returned to **Current source** and the saved review owner/date remained. [Current source after rescan](evidence/0.1.0/rescan-current.png).

Only synthetic test data was used. The disposable containers and volumes were removed. This closes the current-ZIP admin scan, review, uncertain-link and same-second stale-source browser checks; it does not replace a human screen-reader pass or a test on the intended host's PDF storage and theme.

## Current artifact recheck and release limits

On 2026-11-05, the rebuilt ZIP and SHA-256 sidecar matched the standalone and workspace packagers. This exact rebuilt ZIP passed install/activate/deactivate/reactivate and the complete WordPress integration fixture on the tested stack. The fixture now tests upgrading the prior local table schema and detects a changed source whose WordPress modified timestamp remains equal to the scan timestamp. A previous GitHub main CI run exposed the same-second timestamp edge case; the new content fingerprint closes it. Earlier Plugin Check evidence is from a byte-different ZIP with the same distribution headers; the exact GitHub ZIP has the additional browser evidence above.

- Only saved links in published, password-free post/page content are inventoried. The plugin does not read PDFs, test downloads, validate legal content, scan rendered theme output or dynamic builder data, send reminders, or alter files. A match means the saved URL equals the current filtered direct URL of a PDF attachment.
- Scans use a high-water ID and read publication state at cursor time. They are not immutable snapshots. Later source changes require a new scan; changed/deleted rows are labelled in the saved report.
- A public GitHub prerelease exists. No digicyfr.com production publication, WordPress.org submission or live customer-site installation was performed.
