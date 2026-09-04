# Contributing

Bug reports and pull requests are welcome. This is a small, single-purpose module, so the bar is mostly "does it stay small and stay correct."

## Before you open a PR

```sh
composer install
composer test    # phpunit
composer lint    # phpcs, PSR-12
```

CI runs the test suite on PHP 8.2, 8.3, 8.4 and 8.5, style and syntax checks on 8.2, a Semgrep scan, and a coverage job with a hard 90% line-coverage floor. It's cheaper to catch failures locally.

To reproduce the coverage gate you need a coverage driver (`pcov` or `xdebug`):

```sh
php -d pcov.enabled=1 vendor/bin/phpunit --testsuite Unit --coverage-text
```

To reproduce the Semgrep scan without installing anything permanently:

```sh
uvx --from semgrep semgrep scan --config p/php --config p/security-audit \
  --config p/github-actions --metrics=off --error Providers Services Tests .github
```

To reproduce Qlty's static-analysis check, install the CLI and run it against `.qlty/qlty.toml`:

```sh
qlty check --all
```

`radarlint-php` is the same engine SonarQube Cloud runs, so this is the fastest way to see
Sonar's findings without waiting on CI. `Tests/**` is excluded in `.qlty/qlty.toml` for
reasons documented inline there. Anything reported for `Providers/` or `Services/` is real
and should be fixed, not excluded.

### Actions are pinned to SHAs

Every `uses:` in `.github/workflows/` is a full 40-character commit SHA with the version in a trailing comment. Tags are mutable and can be repointed by their owner, which is how several action supply-chain compromises worked, and this repo publishes an artifact that other people's helpdesks install automatically. Semgrep's `p/github-actions` ruleset fails the build on an unpinned tag, so this stays true by itself. Dependabot updates the SHA and the comment together; take its PRs.

## What the tests are protecting

The one thing this module must never do is suppress a notification it shouldn't. A false positive hides a real customer email from every agent, silently and permanently: nobody gets an alert, and nothing looks broken.

That's why `SuppressionDeciderTest` is mostly negative cases, and why every unexpected input is treated as "notify." If you add a condition, add the negative test with it.

`ProviderHooksTest` asserts query counts as well as return values. That isn't premature optimisation: these hooks run on *every* notification of every type, so "costs nothing on the notifications it doesn't touch" is part of the contract. `Tests/Stubs/ExplodingThread` exists to prove the module never resolves the lazy `$thread->conversation` relation for an event it can't act on.

## Architecture, briefly

`Services/SuppressionDecider.php` holds the decision: pure, no framework, database access injected as a callable. `Providers/QuietAutoClosedServiceProvider.php` is the framework-facing half, registering the Eventy hooks and running the queries.

`Tests/Stubs/` provides doubles for the small surface of Laravel and FreeScout the provider touches, so it can be booted and its hooks actually invoked rather than inspected as text. Dev-only; `export-ignore`d from the release archive.

Keep new logic in `Services` where it can be tested without doubles.

## Style

PSR-12, enforced by `phpcs` across the whole repo. Test methods are snake_case (the one relaxed rule); the stub files opt out with `phpcs:ignoreFile` because they deliberately declare several framework doubles per file.

Comments should explain *why*. The *what* is usually obvious from the code, and the non-obvious part here is almost always FreeScout's behaviour, not ours.

## Releasing

Maintainer only:

1. Bump `version` in `module.json`.
2. Move `CHANGELOG.md`'s `[Unreleased]` entries under a new `## [X.Y.Z] - YYYY-MM-DD` heading (today's date), and add link references for the new version and the now-empty `[Unreleased]`.
3. Commit, then push a tag: `git tag v1.2.3 && git push origin v1.2.3`.

The tag triggers `release.yml`, which re-runs the suite and style checks, then gates the release itself:

| Gate | Catches |
| --- | --- |
| Tagged commit is an ancestor of `main` | releasing a side branch that never went through a pull request |
| `module.json`'s version matches the tag, and both are `X.Y.Z` | forgetting step 1, or a malformed tag |
| The new version is semver-greater than every prior release tag | re-tagging the current version, or a downgrade |
| `CHANGELOG.md` has a dated, non-empty entry for the version, with a link reference | forgetting step 2, an empty entry, or a bare heading with no date |
| The built archive's top-level folder and required files | a `.gitattributes` edit that silently guts the release zip |

Everything in that table except the tag/version checks is also enforced by `ChangelogTest.php` and `ModuleJsonTest.php` on every pull request, so a maintainer preparing a release finds out immediately rather than after pushing a tag. The tag-ancestry and version-bump checks are necessarily tag-time-only: neither question makes sense to ask of a commit that hasn't been tagged yet.

Once those pass, the workflow builds the archive, inspects it, creates the release as a **draft**, uploads the archive, then `version.json`, and only then publishes.

That order is deliberate. `version.json` is the file every install polls to discover a new version, so it must be the last thing to appear, and the release must only become "Latest" once a verified archive is already attached. Triggering on the tag rather than on a published release is what makes a failed check harmless: nothing is ever advertised that isn't there.

Pre-release tags (`v1.2.3-rc1`) are not supported; the version guard requires a bare `X.Y.Z`.

## Conduct

See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
