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

        foreach (['/Providers', '/Services', '/module.json', '/LICENSE', '/README.md'] as $required) {
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
    }
}
