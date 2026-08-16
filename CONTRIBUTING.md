# Contributing

Bug reports and pull requests are welcome. This is a small, single-purpose module, so the bar is mostly "does it stay small and stay correct."

## Before you open a PR

```sh
composer install
composer test    # phpunit
composer lint    # phpcs, PSR-12
```

CI runs the test suite on PHP 8.2, 8.3, 8.4 and 8.5, and runs style and syntax checks on 8.2. It's cheaper to catch failures locally.

## What the tests are protecting

The one thing this module must never do is suppress a notification it shouldn't. A false positive hides a real customer email from every agent, silently and permanently — nobody gets an alert, and nothing looks broken.

That's why `SuppressionDeciderTest` is mostly negative cases, and why every unexpected input is treated as "notify." If you add a condition, add the negative test with it.

`ProviderHooksTest` asserts query counts as well as return values. That isn't premature optimisation: these hooks run on *every* notification of every type, so "costs nothing on the notifications it doesn't touch" is part of the contract. `Tests/Stubs/ExplodingThread` exists to prove the module never resolves the lazy `$thread->conversation` relation for an event it can't act on.

## Architecture, briefly

- `Services/SuppressionDecider.php` — the decision. Pure, no framework, database access injected as a callable.
- `Providers/QuietAutoClosedServiceProvider.php` — the framework-facing half: registers the Eventy hooks and runs the queries.
- `Tests/Stubs/` — doubles for the small surface of Laravel and FreeScout the provider touches, so it can be booted and its hooks actually invoked rather than inspected as text. Dev-only; `export-ignore`d from the release archive.

Keep new logic in `Services` where it can be tested without doubles.

## Style

PSR-12, enforced by `phpcs` across the whole repo. Test methods are snake_case (the one relaxed rule); the stub files opt out with `phpcs:ignoreFile` because they deliberately declare several framework doubles per file.

Comments should explain *why* — the *what* is usually obvious from the code, and the non-obvious part here is almost always FreeScout's behaviour, not ours.

## Releasing

Maintainer only:

1. Bump `version` in `module.json`.
2. Add that version's entry to `CHANGELOG.md` (`ModuleJsonTest` fails if you forget, and the release workflow refuses to publish without it).
3. Commit, then push a tag: `git tag v1.2.3 && git push origin v1.2.3`.

The tag triggers `release.yml`, which runs the suite, verifies `module.json`'s version matches the tag, builds the archive, inspects it, creates the release as a **draft**, uploads the archive, then `version.json`, and only then publishes.

That order is deliberate. `version.json` is the file every install polls to discover a new version, so it must be the last thing to appear — and the release must only become "Latest" once a verified archive is already attached. Triggering on the tag rather than on a published release is what makes a failed check harmless: nothing is ever advertised that isn't there.

Pre-release tags (`v1.2.3-rc1`) are not supported; the version guard requires a bare `X.Y.Z`.

## Conduct

See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
