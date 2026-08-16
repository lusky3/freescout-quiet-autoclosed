# Security Policy

## Reporting a vulnerability

Use GitHub's private vulnerability reporting: open the Security tab on this repo and click "Report a vulnerability." That opens a private advisory only the maintainer can see, which is the right way to report anything that shouldn't be public before a fix ships.

Don't open a public issue for a security problem. If you already did, flag it and it'll be converted to a private advisory.

## What counts

The interesting failure mode for this module is **over-suppression**: it decides who gets told about a ticket, so a bug that silences too much means real customer mail arrives and nobody is notified. That's the thing worth reporting.

Concretely:

- Any input that makes `SuppressionDecider::shouldSuppress()` return true for an event other than `EVENT_NEW_CONVERSATION` — including a crafted `$events` array, which reaches this module through the public `subscription.events_by_type` filter and can therefore be reshaped by another module.
- Any input that makes it return true for a conversation that is not closed, or whose `closed_by_user_id` is not the Workflows pseudo-user.
- A way to get the memoized decision for one conversation applied to a different conversation.
- Anything that makes either filter return an empty recipient list when the conversation is unknown, unreadable, or the state read failed. Those paths must fall through to notifying.

Also in scope: `latestVersionUrl` / `latestVersionZipUrl` in `module.json` pointing anywhere other than this repository's own releases, or a change to `.github/workflows/release.yml` that could publish a mismatched or attacker-controlled `version.json` / archive under this project's name.

## What doesn't count

The module's own code reads `conversations.status` and `conversations.closed_by_user_id` (keyed on `conversations.id`), plus `users.id` for one email, and returns a boolean. It writes nothing, exposes no routes, renders no views, accepts no user input, and makes no outbound network requests.

It does declare self-update URLs in `module.json`, which FreeScout core polls against GitHub on its update checks — that traffic is core's, initiated on your install's schedule, and the download-and-apply path is implemented by core rather than here. A flaw in how core fetches or unpacks an update belongs to [FreeScout](https://github.com/freescout-help-desk/freescout).

Reports that amount to "a FreeScout admin can deactivate the module" or "an admin can edit the workflow" aren't vulnerabilities — those people already have the keys.

Notifications *not* being suppressed is a bug, not a vulnerability — open a normal issue.

## Supported versions

Whatever is on `main`. This is a small single-purpose module maintained by one person; there's no LTS branch and no backport policy.

## Response time

No SLA. Expect an acknowledgment within a few days.
