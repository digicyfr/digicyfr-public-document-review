# Local QA — 0.1.0

## Candidate

- ZIP: `dist/digicyfr-public-document-review-0.1.0.zip`
- SHA-256: `39059c80a3acf1d39a6a00581eff56e92268acbb40de67ad79c42713b92a6b15`
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

## Current artifact recheck and release limits

On 2026-11-05, the rebuilt ZIP and SHA-256 sidecar matched the standalone and workspace packagers. This exact rebuilt ZIP passed install/activate/deactivate/reactivate and the complete WordPress integration fixture on the tested stack. Earlier browser and Plugin Check evidence is from a byte-different ZIP with identical runtime files.

- Only saved links in published, password-free post/page content are inventoried. The plugin does not read PDFs, test downloads, validate legal content, scan rendered theme output or dynamic builder data, send reminders, or alter files. A match means the saved URL equals the current filtered direct URL of a PDF attachment.
- Scans use a high-water ID and read publication state at cursor time. They are not immutable snapshots. Later source changes require a new scan; changed/deleted rows are labelled in the saved report.
- No digicyfr.com publication, GitHub release, WordPress.org submission or live-site installation was performed.
