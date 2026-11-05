# digicyfr-public-document-review 0.1.1 release notes

Free GPL-2.0-or-later release for manual WordPress ZIP installation. Source: https://github.com/digicyfr/digicyfr-public-document-review. The protected `release/0.1.1` branch and `v0.1.1` tag record the reviewed source. Install the attached ZIP, not GitHub's source archive, and check its SHA-256 sidecar.

## Fix since 0.1.0

Matched-report **Open source** links now have an underline. The attached v0.1.0 ZIP relied on color alone; axe-core marked that plugin-owned link as a serious `link-in-text-block` violation. The v0.1.0 tag, branch and ZIP remain unchanged. The new version includes the corrected stylesheet.

## Included and tested scope

The plugin scans saved public PDF links in bounded manual batches, conservatively matches Media Library attachments, keeps private review state, and exports a guarded complete-snapshot CSV. It detects same-second source edits. WordPress 7.1, PHP 8.3.33 and MariaDB 11.4.13/InnoDB are the tested stack. The exact ZIP passes syntax, WordPress integration and browser checks. See [docs/QA-0.1.1.md](docs/QA-0.1.1.md) for this fix's browser evidence and [docs/QA-0.1.0.md](docs/QA-0.1.0.md) for the wider workflow.

## Limits

The plugin does not read PDFs, verify downloads or legal validity, or inspect rendered/dynamic page output. A manual screen-reader pass, a representative PDF/offloaded-storage host, multisite and production content patterns remain untested. Some native-control contrast checks are inconclusive in automated tooling. The unique `Update URI` prevents a directory slug collision but does not provide automatic updates. WordPress.org distribution would require a separate review.
