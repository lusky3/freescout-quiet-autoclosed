# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Three release gates that previously did not exist: the tagged commit must
  be an ancestor of `main`, the new version must be semver-greater than
  every prior release tag, and `phpcs` runs without `-n` on the release path
  too, so a warning-level defect can no longer slip through by tagging a
  commit CI never fully checked.
- `Tests/Unit/ChangelogTest.php`, which pins CHANGELOG.md's structure to
  module.json's version on every pull request: a dated, non-empty entry, a
  link reference, and an `[Unreleased]` compare link pointing at the current
  release. Catches "changelog not updated" before a release is ever tagged,
  not just at tag time.
- `module.json` now declares `requiredAppVersion`, matching every official
  FreeScout module (Workflows, SpamFilter, MobileNotifications, Mentions) and
  letting core warn an admin before activation on a FreeScout older than the
  one this module was verified against, instead of the module silently doing
  nothing. A test pins it to the version README.md's "Verified against" line
  names, so the two cannot drift apart.
- Semgrep scan (`p/php`, `p/security-audit`, `p/github-actions`) on pull
  requests and weekly, using open-source rulesets with metrics disabled. The
  GitHub Actions ruleset keeps the SHA pinning below from regressing.
- Coverage job with a 90% line-coverage floor enforced in-repo, so the gate
  holds on forks and without any external service. Codecov, Codacy and Qlty
  all receive the report as reporting-only publishers.
- SonarQube Cloud CI-based analysis, with the coverage report attached.
- `.qlty/qlty.toml`, running static analysis (radarlint-php, actionlint,
  zizmor, editorconfig-checker, trufflehog, osv-scanner) as its own PR check,
  independent of Qlty's coverage upload above.

### Fixed

- CI's `phpcs -n` flag was suppressing warnings, hiding a constant with no
  visibility modifier and an over-long line that a separate tool (Codacy)
  caught from its own phpcs run. `-n` is gone from CI and `composer lint`.
- `shouldSuppress()` had five early returns against PSR guidance of three;
  refactored to two plus a private `remember()`, without changing behaviour.
- A handful of PHPMD/SonarQube findings: an inconsistently-named property,
  two unused closure parameters, `.editorconfig` claiming an indent size that
  matched none of Markdown, XML or TOML, and a markdownlint rule that Keep a
  Changelog's repeated section headings can never satisfy by default.

### Security

- Every GitHub Action is now pinned to a full commit SHA instead of a mutable
  tag, and each CI job runs StepSecurity's Harden-Runner in audit mode. The
  release pipeline builds the artifact that installs itself onto other
  people's helpdesks, so it is treated as part of the security surface.

## [1.0.0] - 2026-08-15

First release.

### Added

- Suppresses the "new conversation" notification for conversations an automatic
  Workflow has already closed. Covers every medium at once — email, browser
  push, the notifications menu, and the MobileNotifications module — by
  emptying the recipient list rather than intercepting each channel.
- Suppression requires all three of: the event is `EVENT_NEW_CONVERSATION`, the
  conversation is closed when recipients are picked, and its
  `closed_by_user_id` is the Workflows module's pseudo-user. Replies,
  assignments, notes, and conversations closed by a human are untouched.
- Degrades to a no-op when the Workflows module is absent or deactivated: with
  no pseudo-user to match, nothing is ever attributed to a workflow.
- Fails open throughout. A missing conversation, a malformed event list, or a
  database error lets the notification through and is logged, on the principle
  that over-notifying is a nuisance while under-notifying hides real tickets.
- Costs no queries on notifications it cannot act on: the event is checked
  before any database read, and before the lazy `$thread->conversation`
  relation is resolved.

[Unreleased]: https://github.com/lusky3/freescout-quiet-autoclosed/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/lusky3/freescout-quiet-autoclosed/releases/tag/v1.0.0
