## What this changes

<!-- One or two sentences. -->

## Why

<!-- What breaks, or what's missing, without it. -->

## Checklist

- [ ] `composer test` passes
- [ ] `composer lint` passes
- [ ] If this changes what gets suppressed, there's a **negative** test for it:
      a case that must still notify
- [ ] If this touches the hooks, their priorities, or their argument counts,
      `Tests/Unit/ProviderHooksTest.php` is updated to match
- [ ] `CHANGELOG.md` updated under `[Unreleased]`

## How you verified it

<!-- Unit tests are the floor. If you ran it against a real FreeScout install,
     say what you observed. That's the part CI can't do. -->
