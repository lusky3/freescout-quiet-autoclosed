# Quiet Auto-Closed

[![Tests](https://github.com/lusky3/freescout-quiet-autoclosed/actions/workflows/tests.yml/badge.svg)](https://github.com/lusky3/freescout-quiet-autoclosed/actions/workflows/tests.yml)
[![Lint](https://github.com/lusky3/freescout-quiet-autoclosed/actions/workflows/lint.yml/badge.svg)](https://github.com/lusky3/freescout-quiet-autoclosed/actions/workflows/lint.yml)
[![Semgrep](https://github.com/lusky3/freescout-quiet-autoclosed/actions/workflows/semgrep.yml/badge.svg)](https://github.com/lusky3/freescout-quiet-autoclosed/actions/workflows/semgrep.yml)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/dcff4f80bb01484cb623c337ced05534)](https://app.codacy.com/gh/lusky3/freescout-quiet-autoclosed/dashboard)
[![codecov](https://codecov.io/gh/lusky3/freescout-quiet-autoclosed/branch/main/graph/badge.svg)](https://codecov.io/gh/lusky3/freescout-quiet-autoclosed)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A FreeScout module that stops the "new conversation" alert for tickets an automatic Workflow has already closed.

If you use Workflows to file automated mail — registration confirmations, payment notifications, bounce messages — you already know the problem: the workflow closes and tags the ticket correctly, and everyone still gets emailed about it.

## Why the workflow can't do this itself

It's an ordering problem in FreeScout core, not a misconfigured workflow. In `app/Console/Commands/FetchEmails.php`:

```text
:1318   Eventy::filter('conversation.created_by_customer')   <- SpamFilter hooks here
:1329   event(new CustomerCreatedConversation)               <- the alert is registered
:1330   Eventy::action('conversation.created_by_customer')   <- Workflows run here, one line too late
:189    Subscription::processEvents()                        <- dispatches the email, 15s delay
```

The notification is queued before your workflow gets a chance to close anything. Nothing downstream reconsiders it: `app/Listeners/SendNotificationToUsers.php` only bails out on `STATUS_SPAM`, and neither `Subscription::usersToNotify()` nor the queued `SendNotificationToUsers` job re-reads the status. Core says so plainly at `app/Subscription.php:16`:

```php
// Changing ticket status does not fire event
```

Closing is invisible to the notifier, in both directions. That's why "add a workflow action that closes it faster" can never work.

This was [asked for upstream](https://github.com/freescout-help-desk/freescout/issues/5112) in December 2025 — a workflow action called "Remove from notifications", for exactly this use case — and closed the same day with a pointer to the feature-request board. It hasn't been built. [#3740](https://github.com/freescout-help-desk/freescout/issues/3740) is the same complaint from the auto-reply direction, answered with "There is no such feature for now."

## What this does instead

It drops the recipients for that one alert, at the point the pipeline picks them — inside `Subscription::processEvents()`, which runs well after the workflow.

Two filters:

| Hook | Where | Why |
| --- | --- | --- |
| `subscription.subscriptions` | `app/Subscription.php:254` | cuts the core recipients |
| `subscription.users_to_notify` | `app/Subscription.php:303` | registered at priority 50 so it runs last, after modules like Mentions add their own |

Emptying the recipient list covers **every medium at once** — email, browser push, the notifications menu, and MobileNotifications, which reads the same `$notify` array via `subscription.process_events`. There's no per-channel interception to keep in sync.

A notification is suppressed only when **all three** hold:

1. the event is `EVENT_NEW_CONVERSATION`, and
2. the conversation is `STATUS_CLOSED` when recipients are picked, and
3. its `closed_by_user_id` is the Workflows module's pseudo-user (`fsworkflow@example.org`).

Condition 3 is worth explaining, because the obvious alternative is wrong. Workflows writes a line-item thread with `action_type = 201` whenever an automatic workflow runs — for *any* action, including a bare "add tag". Matching on that would silence tickets a workflow merely touched and a human closed. On the install this was developed against, 704 closed conversations carried a `201` thread while only 702 had actually been closed by a workflow; the other two were human-closed, and matching on `201` would have hidden them. `Conversation::setStatus()` records who closed a conversation, and the workflow close action passes its own pseudo-user, so `closed_by_user_id` answers the question exactly.

Replies, assignments, notes, and conversations you closed by hand are untouched. So is everything else if the Workflows module isn't installed — with no pseudo-user to match, the module is inert rather than erroring.

The bias is deliberate: anything unexpected — a missing conversation, a malformed event list, a database error — falls through to *notify*, and is logged. Over-notifying is a nuisance; under-notifying hides real tickets.

## Requirements

- FreeScout with the [Workflows module](https://freescout.net/module/workflows/). Without it this module does nothing at all — deliberately: `module.json` does **not** declare `requiredModules: {"workflows": ...}`, because that would stop this module from *installing* without Workflows, rather than letting it install and stay inert. Do not add that field; it would break the documented degrade-to-no-op behaviour above.
- Verified against **FreeScout 1.8.232**, which `module.json`'s `requiredAppVersion` also names — the same convention every official module (Workflows, SpamFilter, MobileNotifications, Mentions) uses, so core can warn an admin before activation on an older install instead of the module silently doing nothing. It depends on the `subscription.subscriptions` and `subscription.users_to_notify` filters and on `Conversation.closed_by_user_id`, all long-standing core; a test pins the README line and `requiredAppVersion` together so they cannot drift apart silently.

The module's own code is conservative PHP with no version-specific syntax. `composer.json` declares `^8.2` because that is what CI covers (8.2 through 8.5, the last being what current FreeScout images ship) — it is a development constraint, not an install requirement, and nothing evaluates it at install time.

## Install

Updates work the same way regardless of how you install this: FreeScout core itself checks
`latestVersionUrl` for every non-official module on every load of **Manage → Modules**, and
shows an **Update Now** button when `module.json`'s declared version is newer than what's
installed (`app/Http/Controllers/ModulesController.php`'s `modules()` method, and
`App\Module::updateModule()`, which downloads and extracts `latestVersionZipUrl` in place —
verified directly against core, not inferred). No Module Manager, no license key, nothing
module-specific: this is how *any* third-party module with those two `module.json` fields
updates. Module Manager's own role is narrower — installing a module from a GitHub URL in the
first place, which stock core has no UI for.

### With Module Manager

If you use [FreeScout Module Manager](https://github.com/lusky3/freescout-module-manager), add this repository and install it from the modules list.

### By hand

```sh
cd Modules   # your FreeScout install's Modules directory
git clone https://github.com/lusky3/freescout-quiet-autoclosed.git QuietAutoClosed
chown -R www-data:www-data QuietAutoClosed   # match your install's PHP user
```

The directory name **must** be `QuietAutoClosed`. FreeScout maps `Modules\ => Modules/`, and `module.json` declares `Modules\QuietAutoClosed\Providers\QuietAutoClosedServiceProvider`, so any other name is a class-not-found on activation. (The lowercase `quietautoclosed` is the *alias*, which is a different thing — it's the key in the modules table.)

There is nothing to build and no dependencies to install; the module has no runtime `vendor/`.

Then restart your PHP worker — some images only register modules at boot — and activate the module under **Manage → Modules**. No license key: it's third-party and MIT-licensed.

Never run `artisan` as root inside a containerised install; root-owned files under `storage/` and `bootstrap/cache/` are a classic way to wedge FreeScout into a permanent HTTP 500. On Docker, run it as the PHP user:

```sh
docker exec -u www-data <container> php artisan tinker
```

## Verifying it works

The honest test is to wait for the next automated email and confirm no alert arrives. To check the wiring immediately instead, run this through tinker as your PHP user:

```php
$wf = \App\User::where('email', 'fsworkflow@example.org')->value('id');
$conv = $wf ? \App\Conversation::where('status', \App\Conversation::STATUS_CLOSED)
    ->where('closed_by_user_id', $wf)->first() : null;

if (!$conv) {
    echo "No workflow-closed conversation to test against yet — that's fine, it just means\n";
    echo "no workflow has closed anything on this install. Wait for one and re-run.\n";
} else {
    $P = \Modules\QuietAutoClosed\Providers\QuietAutoClosedServiceProvider::class;
    $thread = \App\Thread::where('conversation_id', $conv->id)->first();

    // Expect an empty collection: recipients dropped.
    $P::flushCache();
    \Eventy::filter('subscription.subscriptions', collect(['x']), $conv,
        [\App\Subscription::EVENT_NEW_CONVERSATION], null)->count();

    // Expect 1: a reply on the same conversation still notifies.
    $P::flushCache();
    \Eventy::filter('subscription.subscriptions', collect(['x']), $conv,
        [\App\Subscription::EVENT_CUSTOMER_REPLIED_TO_MY], null)->count();

    // Expect []: the second hook agrees.
    $P::flushCache();
    \Eventy::filter('subscription.users_to_notify', ['x'],
        \App\Subscription::EVENT_TYPE_NEW, [\App\Subscription::EVENT_NEW_CONVERSATION], $thread);
}
```

`flushCache()` between steps matters: decisions are memoized per conversation for the length of a notification pass, so without it you'll see the first answer repeated.

For the negative case, repeat the first call with a conversation you closed by hand (`closed_by_user_id` set to a real user) and with an open one. Both should come back with `['x']` intact.

## Uninstall / disable

Deactivate under **Manage → Modules**, or from tinker:

```php
\App\Module::setActive('quietautoclosed', 0);
```

then clear the cache — `php artisan freescout:clear-cache` — or the cached config still lists the provider and the module keeps booting. Delete the directory to remove it entirely.

Either way no data is touched. This module only ever decides who gets told; it writes nothing.

## Development

```sh
composer install
composer test    # phpunit
composer lint    # phpcs, PSR-12
```

`Services/SuppressionDecider.php` holds the decision and reaches the database through an injected callable. `Providers/QuietAutoClosedServiceProvider.php` does the framework-facing work. Both are unit-tested: `Tests/Stubs/` provides doubles for the small surface of Laravel and FreeScout the provider touches, so it can be booted for real, its hooks recorded, and those hooks invoked — including assertions on *how many queries* a given notification costs.

See [CONTRIBUTING.md](CONTRIBUTING.md), [CHANGELOG.md](CHANGELOG.md), and [SECURITY.md](SECURITY.md).

### What CI checks

| Check | What it's for |
| --- | --- |
| Tests, PHP 8.2 – 8.5 | The suite, plus `php -l` on every version, because the module ships as plain files onto whatever PHP the install runs. |
| Coverage | Line coverage with a hard 90% floor enforced in-repo, so the gate holds on forks and without any external service. |
| Lint | PSR-12 via phpcs, `composer validate --strict`, and a YAML parse of every workflow. |
| Semgrep | `p/php`, `p/security-audit` and `p/github-actions`, open-source rulesets only — no account, no token, `--metrics=off`. |
| Codacy | Repository-level analysis, scoped by `.codacy.yml` to `Providers/` and `Services/` — the code that actually ships. Also receives the coverage report. |
| Codecov | Coverage trend and per-PR annotations. Reporting only — see below. |
| Qlty | Two roles: uploads the coverage report (reporting only, same as Codecov), and independently runs static analysis against `.qlty/qlty.toml` — radarlint-php (SonarSource's own engine, the same one behind the SonarQube Cloud row below), plus actionlint, zizmor, editorconfig-checker, trufflehog and osv-scanner. That scan posts its own PR check and is scoped to exclude `Tests/**`, with the reasons written inline in the config file. |
| Socket | Supply-chain checks, account-wide. |
| SonarQube Cloud | CI-based analysis with the coverage report attached. Requires Automatic Analysis to stay off for the project — the two modes are mutually exclusive. |

The three coverage uploads (Codecov, Codacy, Qlty's coverage step) are **reporting, not gating**. Each is guarded on its token being present, so a pull request from a fork skips it rather than failing, and each is `continue-on-error`. The gate is the in-repo threshold, which needs no network and no account — an unreachable dashboard should never be the reason a correct change can't merge. Qlty's separate static-analysis scan is not a coverage upload and is not exempted by this — see the row above.

Every GitHub Action is pinned to a full commit SHA rather than a tag, and each job starts with StepSecurity's Harden-Runner in audit mode. This repo builds an artifact that installs itself onto other people's helpdesks, so its build pipeline is treated as part of the security surface — see [SECURITY.md](SECURITY.md).

## Known limits

- **It silences the in-app notifications menu too**, not just email and push. That's usually what people want, but an auto-closed ticket won't appear in the bell menu either. It's still in the mailbox, closed and tagged, exactly as the workflow left it.
- **Only conversations created by customers are affected in practice.** For conversations an agent creates in the UI or via the API, core defers the workflow to a background job while the notification is registered immediately — so the workflow hasn't closed anything yet when recipients are picked, and nothing is suppressed. Fails open, which is the right direction, but don't expect it to cover that path.
- **Not configurable in 1.0.** The rule is hardcoded. If you want *workflow-tagged* rather than *workflow-closed*, a per-mailbox opt-out, or a settings toggle, open an issue — it's the obvious next step and I'd rather build it around a real use case than guess.
- **It identifies the workflow user by email** (`fsworkflow@example.org`, the Workflows module's own constant). If that ever changes upstream, suppression stops — failing open, so notifications resume rather than disappear.
- **Decisions are memoized per notification pass**, cleared on `subscription.process_events`. Under a persistent worker model (Octane and the like) that reuses a process across requests, call `QuietAutoClosedServiceProvider::flushCache()` between requests. Stock FreeScout runs php-fpm and per-invocation artisan commands, where this doesn't arise.

## License

MIT — see [LICENSE](LICENSE).
