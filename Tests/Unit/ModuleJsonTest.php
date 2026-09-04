<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * module.json is the contract between this repo and both FreeScout core and
 * the release workflow. Every value asserted here is one that breaks
 * installation or self-update if it drifts.
 */
class ModuleJsonTest extends TestCase
{
    private const REPO_URL = 'https://github.com/lusky3/freescout-quiet-autoclosed';

    private function moduleJson(): array
    {
        $path = __DIR__ . '/../../module.json';
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data, 'module.json must decode to a JSON object.');

        return $data;
    }

    public function test_version_is_a_dotted_triple(): void
    {
        $data = $this->moduleJson();

        $this->assertArrayHasKey('version', $data);
        $this->assertIsString($data['version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $data['version']);
    }

    /**
     * The alias is the key in the modules table - what activation, and
     * App\Module::setActive(), look the module up by. Changing it orphans
     * every existing install, which silently stops suppressing.
     *
     * It is NOT the install directory name: that has to match the namespace
     * segment in the providers entry below, because FreeScout maps
     * `Modules\ => Modules/`.
     */
    public function test_alias_is_stable(): void
    {
        $data = $this->moduleJson();

        $this->assertSame('quietautoclosed', $data['alias'] ?? null);
    }

    /**
     * authorUrl's host must never match config('app.freescout_url')'s host on
     * a real install - that is how core's App\Module::isOfficial() decides
     * "official" (license activation required) vs "third-party" (free to
     * activate). A github.com URL can never collide with freescout.net.
     */
    public function test_declares_a_github_author_url(): void
    {
        $data = $this->moduleJson();

        $this->assertSame(self::REPO_URL, $data['authorUrl'] ?? null);
        $this->assertSame('github.com', parse_url($data['authorUrl'], PHP_URL_HOST));
    }

    /**
     * Both URLs use GitHub's "latest release" static alias, which always
     * resolves to whichever release is currently marked Latest.
     * .github/workflows/release-assets.yml uploads assets under exactly these
     * two filenames on every published release.
     */
    public function test_latest_version_url_is_the_stable_release_asset_url(): void
    {
        $data = $this->moduleJson();

        $this->assertSame(
            self::REPO_URL . '/releases/latest/download/version.json',
            $data['latestVersionUrl'] ?? null
        );
    }

    public function test_latest_version_zip_url_is_the_stable_release_asset_url(): void
    {
        $data = $this->moduleJson();

        $this->assertSame(
            self::REPO_URL . '/releases/latest/download/freescout-quiet-autoclosed.zip',
            $data['latestVersionZipUrl'] ?? null
        );
    }

    /**
     * The provider FreeScout boots must actually be on disk, at the path the
     * PSR-4 mapping in composer.json implies.
     */
    public function test_declared_provider_exists_on_disk(): void
    {
        $data = $this->moduleJson();

        $this->assertSame(
            ['Modules\\QuietAutoClosed\\Providers\\QuietAutoClosedServiceProvider'],
            $data['providers'] ?? null
        );

        $relative = str_replace('Modules\\QuietAutoClosed\\', '', $data['providers'][0]);
        $path = __DIR__ . '/../../' . str_replace('\\', '/', $relative) . '.php';

        $this->assertFileExists($path);
    }

    public function test_declares_the_mit_license(): void
    {
        $data = $this->moduleJson();

        $this->assertSame('MIT', $data['license'] ?? null);
        $this->assertFileExists(__DIR__ . '/../../LICENSE');
    }

    /**
     * Release hygiene: the version being shipped must already be written up.
     * Catches the "tagged a release, forgot the changelog" slip before the
     * tag exists.
     */
    public function test_version_has_a_changelog_entry(): void
    {
        $data = $this->moduleJson();
        $changelog = (string) file_get_contents(__DIR__ . '/../../CHANGELOG.md');

        $this->assertStringContainsString(
            '## [' . $data['version'] . ']',
            $changelog,
            'CHANGELOG.md has no entry for the version in module.json.'
        );
    }

    /**
     * composer.json and module.json describe the same package to two
     * different tools; a mismatched license or homepage is a packaging bug.
     */
    public function test_agrees_with_composer_json(): void
    {
        $data = $this->moduleJson();
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

        $this->assertSame($data['license'], $composer['license'] ?? null);
        $this->assertSame($data['authorUrl'], $composer['homepage'] ?? null);
    }

    /**
     * Every official FreeScout module declares this (Workflows, SpamFilter,
     * MobileNotifications, Mentions all checked directly on a live install);
     * it is what lets core warn an admin before activation on a FreeScout
     * that predates the filters this module hooks, rather than the module
     * silently doing nothing - the failure mode the README documents.
     *
     * Pinned to the exact version the README's "Verified against" line
     * names, so the two cannot drift apart silently.
     */
    public function test_declares_the_verified_required_app_version(): void
    {
        $data = $this->moduleJson();
        $readme = (string) file_get_contents(__DIR__ . '/../../README.md');

        $this->assertArrayHasKey('requiredAppVersion', $data);

        $matched = preg_match('/Verified against \*\*FreeScout ([\d.]+)\*\*/', $readme, $m);
        $this->assertSame(1, $matched, "README.md's \"Verified against\" line could not be found or parsed.");

        $this->assertSame(
            $m[1],
            $data['requiredAppVersion'],
            'module.json requiredAppVersion and README\'s "Verified against" version have drifted apart.'
        );
    }
}
