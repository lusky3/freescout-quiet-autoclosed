# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
