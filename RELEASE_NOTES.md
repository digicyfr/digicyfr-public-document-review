# digicyfr-public-document-review 0.1.0 release notes

This is the first free GPL-2.0-or-later release for manual ZIP installation. Source: https://github.com/digicyfr/digicyfr-public-document-review. The `release/0.1.0` branch records the reviewed version. The branch alone is source code; use the attached `digicyfr-public-document-review-0.1.0.zip` and verify its `.sha256` sidecar before installation.

## Included

Bounded manual scan of saved public PDF links, conservative Media Library matching, private attachment review state, guarded complete-snapshot CSV, and same-second source-edit detection.

## Verification and compatibility

WordPress 7.1, PHP 8.3.33 and MariaDB 11.4.13/InnoDB. The exact GitHub prerelease ZIP passed install, integration fixtures including failure cases, and a post-tag Chromium admin flow: complete scan, matched PDF, uncertain external link, owner/date save, CSV, 390px view, same-second stale-source detection and rescan. PHP/JS syntax and WPCS passed. Plugin Check ran on an earlier runtime-identical ZIP and reported only the documented self-host distribution findings.

## Limits and follow-up checks

A manual screen-reader pass, a representative PDF/offloaded-storage check on the intended host, multisite behavior, and production content patterns remain untested. It does not read PDFs, test downloads, certify document validity, or inspect rendered/dynamic page output. Two InnoDB tables are required.

The `Update URI` protects this self-hosted plugin from an unrelated WordPress.org plugin with the same slug. It does not provide automatic updates. WordPress.org submission would need a separate review and distribution-specific changes. See [QA record](docs/QA-0.1.0.md) for the detailed local evidence.
