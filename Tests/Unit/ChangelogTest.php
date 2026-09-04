<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CHANGELOG.md and module.json are two files that must agree, and nothing
 * enforces that but a human remembering to update both. This suite pins the
 * three-way invariant that makes "changelog not updated" and "version not
 * bumped" mistakes fail loudly, on every PR - not only at tag time:
 *
 *   1. module.json's version has a dated, non-empty CHANGELOG entry.
 *   2. that entry's date is not in the future.
 *   3. that version has a Keep a Changelog link-reference footer.
 *   4. [Unreleased]'s compare link points at that same version's tag.
 *
 * Point 4 is the one that catches the mistake this repo actually made once:
 * PR #2 and PR #3 shipped real fixes without ever touching CHANGELOG.md's
 * [Unreleased] section, which was only caught and backfilled by hand later.
 * These tests do not detect *that* specific omission (nothing can, short of
 * requiring every PR touch the changelog) - what they pin is the structural
 * contract a maintainer relies on when they finally do sit down to cut a
 * release: the currently-released version's entry is real, dated, and
 * linked, and Unreleased is still comparing against the right baseline.
 */
class ChangelogTest extends TestCase
{
    private const REPO_URL = 'https://github.com/lusky3/freescout-quiet-autoclosed';

    private function moduleVersion(): string
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../../module.json'), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('version', $data);

        return $data['version'];
    }

    private function changelog(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../CHANGELOG.md');
    }

    /**
     * A bare `## [X.Y.Z]` heading is not enough: Keep a Changelog requires a
     * release date, and a heading with no date is what a maintainer gets if
     * they paste the version number but never actually finish the entry.
     */
    public function test_the_released_version_has_a_dated_heading(): void
    {
        $version = $this->moduleVersion();
        $changelog = $this->changelog();

        $pattern = '/^## \[' . preg_quote($version, '/') . '\] - (\d{4}-\d{2}-\d{2})$/m';
        $matched = preg_match($pattern, $changelog, $m);

        $this->assertSame(
            1,
            $matched,
            "CHANGELOG.md has no dated \"## [{$version}] - YYYY-MM-DD\" heading. "
                . 'A bare "## [' . $version . ']" with no date does not satisfy this - '
                . 'add the release date.'
        );
    }

    /**
     * Catches the specific mistake this project already made once: v1.0.0
     * was first written up dated one day in the future. A date that has not
     * happened yet is not a release date, it is a typo.
     */
    public function test_the_released_versions_date_is_not_in_the_future(): void
    {
        $version = $this->moduleVersion();
        $changelog = $this->changelog();

        $pattern = '/^## \[' . preg_quote($version, '/') . '\] - (\d{4}-\d{2}-\d{2})$/m';
        if (preg_match($pattern, $changelog, $m) !== 1) {
            $this->markTestSkipped('Dated heading missing - covered by test_the_released_version_has_a_dated_heading.');
        }

        $entryDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], new \DateTimeZone('UTC'));
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        $this->assertLessThanOrEqual(
            $today,
            $entryDate,
            "CHANGELOG.md dates {$version} as {$m[1]}, which is in the future."
        );
    }

    /**
     * The entry must say something. An empty section between this version's
     * heading and the next is a heading pasted in isolation - the actual
     * notes never got written.
     */
    public function test_the_released_version_has_non_empty_notes(): void
    {
        $version = $this->moduleVersion();
        $changelog = $this->changelog();

        $matched = preg_match(
            '/^## \[' . preg_quote($version, '/') . '\][^\n]*\n(.*?)(?=^## \[|^\[[^\]]+\]:\s|\z)/ms',
            $changelog,
            $m
        );
        $this->assertSame(1, $matched, "Could not locate the {$version} section to check its body.");

        $this->assertNotSame(
            '',
            trim($m[1]),
            "CHANGELOG.md's entry for {$version} has a heading but no content under it."
        );
    }

    /**
     * Keep a Changelog's link-reference footer must include this version,
     * pointing at its GitHub release - otherwise the heading renders as
     * plain text instead of a link once GitHub displays the file.
     */
    public function test_the_released_version_has_a_link_reference(): void
    {
        $version = $this->moduleVersion();
        $changelog = $this->changelog();

        $this->assertStringContainsString(
            '[' . $version . ']: ' . self::REPO_URL . '/releases/tag/v' . $version,
            $changelog,
            "CHANGELOG.md is missing the link-reference footer for {$version}."
        );
    }

    /**
     * [Unreleased] must compare against the version currently in
     * module.json - the last version actually released - not a stale one.
     * This is the invariant that catches a changelog left un-rotated after a
     * release: if module.json moves to a new version but this compare link
     * still names the old one, the two have drifted.
     */
    public function test_unreleased_compares_against_the_released_version(): void
    {
        $version = $this->moduleVersion();
        $changelog = $this->changelog();

        $this->assertStringContainsString(
            '[Unreleased]: ' . self::REPO_URL . '/compare/v' . $version . '...HEAD',
            $changelog,
            "CHANGELOG.md's [Unreleased] compare link does not point at v{$version} "
                . '(module.json\'s current version). If a release was just cut, update this '
                . 'link to compare against the new tag.'
        );
    }
}
