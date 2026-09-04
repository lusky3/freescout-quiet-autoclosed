<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The release workflow and module.json have to agree on two strings that
 * nothing else checks, and that fail in ways nobody notices for a long time:
 *
 *   - the archive's top-level folder, which must match the namespace segment
 *     FreeScout resolves the provider through. Get it wrong and the module
 *     installs but will not activate.
 *   - the asset filename, which module.json's latestVersionZipUrl points at.
 *     Get it wrong and every existing install's self-update 404s.
 *
 * Neither is exercised until someone cuts a release, so they are pinned here
 * instead - on every pull request.
 */
class ReleaseWorkflowTest extends TestCase
{
    private function workflow(): string
    {
        $path = __DIR__ . '/../../.github/workflows/release.yml';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function moduleJson(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../module.json'), true);
    }

    /**
     * FreeScout maps `Modules\ => Modules/`, so the install directory - and
     * therefore the archive prefix - must equal the namespace segment between
     * `Modules\` and the rest of the provider FQCN.
     */
    public function test_archive_prefix_matches_the_provider_namespace(): void
    {
        $provider = $this->moduleJson()['providers'][0];

        $parts = explode('\\', $provider);
        $this->assertSame('Modules', $parts[0]);
        $segment = $parts[1];

        $this->assertStringContainsString(
            '--prefix=' . $segment . '/',
            $this->workflow(),
            'The release archive prefix must match the module namespace segment.'
        );
    }

    public function test_archive_filename_matches_the_declared_download_url(): void
    {
        $expected = basename($this->moduleJson()['latestVersionZipUrl']);
        $workflow = $this->workflow();

        $this->assertStringContainsString('-o ' . $expected, $workflow);
        $this->assertStringContainsString('gh release upload "$TAG_NAME" ' . $expected, $workflow);
    }

    public function test_version_json_asset_matches_the_declared_url(): void
    {
        $expected = basename($this->moduleJson()['latestVersionUrl']);

        $this->assertSame('version.json', $expected);
        $this->assertStringContainsString('gh release upload "$TAG_NAME" version.json', $this->workflow());
    }

    /**
     * version.json is the file every install polls to learn a new version
     * exists. If it can land before the archive it announces, an interrupted
     * run advertises a download that 404s.
     */
    public function test_the_archive_is_uploaded_before_version_json(): void
    {
        $workflow = $this->workflow();

        $zip = strpos($workflow, 'gh release upload "$TAG_NAME" freescout-quiet-autoclosed.zip');
        $version = strpos($workflow, 'gh release upload "$TAG_NAME" version.json');

        $this->assertNotFalse($zip);
        $this->assertNotFalse($version);
        $this->assertLessThan($version, $zip, 'The archive must be uploaded before version.json announces it.');
    }

    /**
     * A tag can be cut from a commit that never had green CI, so the suite
     * has to run on the release path too.
     */
    public function test_tests_run_before_the_release_is_created(): void
    {
        $workflow = $this->workflow();

        $tests = strpos($workflow, 'phpunit --testsuite Unit');
        $create = strpos($workflow, 'gh release create');

        $this->assertNotFalse($tests, 'The release workflow must run the test suite.');
        $this->assertNotFalse($create);
        $this->assertLessThan($create, $tests, 'Tests must gate the release, not follow it.');
    }

    /**
     * The release must only become "Latest" once a verified artifact is
     * attached, so it is created as a draft and un-drafted at the very end.
     */
    public function test_the_release_is_drafted_first_and_published_last(): void
    {
        $workflow = $this->workflow();

        $draft = strpos($workflow, 'gh release create "$TAG_NAME" --draft');
        $publish = strpos($workflow, '--draft=false');

        $this->assertNotFalse($draft);
        $this->assertNotFalse($publish);
        $this->assertLessThan($publish, $draft);
    }

    /**
     * .gitattributes decides what `git archive` ships. Excluding something
     * the module needs at runtime would produce an installable-looking zip
     * that cannot boot, so the runtime paths are pinned as never-excluded.
     */
    public function test_gitattributes_never_excludes_a_runtime_path(): void
    {
        $gitattributes = (string) file_get_contents(__DIR__ . '/../../.gitattributes');

        foreach (['/Providers', '/Services', '/Public', '/module.json', '/LICENSE', '/README.md'] as $required) {
            $this->assertDoesNotMatchRegularExpression(
                '/^' . preg_quote($required, '/') . '\s+export-ignore/m',
                $gitattributes,
                $required . ' must ship in the release archive.'
            );
        }
    }

    public function test_the_release_verifies_the_built_archive(): void
    {
        $workflow = $this->workflow();

        $this->assertStringContainsString('unzip -Z1', $workflow, 'The built archive should be inspected.');
        $this->assertStringContainsString('QuietAutoClosed/module.json', $workflow);
        $this->assertStringContainsString(
            'QuietAutoClosed/Public/img/icon.svg',
            $workflow,
            'The module icon must be a required file in the built archive, or an install gets a blank icon.'
        );
    }

    /**
     * A tag can be pushed from any branch. Without this gate, a release
     * could ship a commit that never went through a pull request or its
     * checks - exactly the class of mistake tag-based release triggers are
     * otherwise prone to.
     */
    public function test_the_release_requires_the_tag_to_be_on_main(): void
    {
        $workflow = $this->workflow();

        $this->assertStringContainsString('git merge-base --is-ancestor', $workflow);
        $this->assertStringContainsString('origin/main', $workflow);

        $ancestry = strpos($workflow, 'git merge-base --is-ancestor');
        $create = strpos($workflow, 'gh release create');

        $this->assertNotFalse($ancestry);
        $this->assertNotFalse($create);
        $this->assertLessThan($create, $ancestry, 'The main-ancestry check must run before the release is created.');
    }

    /**
     * Matching the tag is not the same as having moved forward: re-tagging
     * the current version, or fat-fingering a lower one, would otherwise
     * pass every other check and still publish.
     */
    public function test_the_release_requires_the_version_to_increase(): void
    {
        $workflow = $this->workflow();

        $this->assertStringContainsString('version_compare(', $workflow);

        $bump = strpos($workflow, 'version_compare(');
        $create = strpos($workflow, 'gh release create');

        $this->assertNotFalse($bump);
        $this->assertNotFalse($create);
        $this->assertLessThan($create, $bump, 'The version-bump check must run before the release is created.');
    }

    /**
     * -n suppresses phpcs warnings. That flag is exactly what let a real
     * defect - a constant with no visibility modifier - ship silently in a
     * past release until an external tool caught it independently; see
     * CHANGELOG.md. The release path re-runs phpcs as a safety net against
     * tagging a commit that skipped CI, and that net has no warning-shaped
     * holes in it.
     */
    public function test_the_release_style_check_does_not_suppress_warnings(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/phpcs\s+-n\b/',
            $this->workflow(),
            'The release workflow must not suppress phpcs warnings with -n.'
        );
    }
}
