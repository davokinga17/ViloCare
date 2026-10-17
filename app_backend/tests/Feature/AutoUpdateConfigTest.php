<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the auto-update wiring in electron/ (same style as DesktopPerformanceConfigTest): things that would
 * silently weaken update safety if someone edited them without noticing.
 */
class AutoUpdateConfigTest extends TestCase
{
    private function electronFile(string $path): string
    {
        return file_get_contents(base_path('../electron/'.$path));
    }

    public function test_publish_block_points_at_a_public_github_repo_and_holds_no_token(): void
    {
        $yml = $this->electronFile('electron-builder.yml');

        $this->assertMatchesRegularExpression('/^publish:\s*\n\s+provider: github\s*\n\s+owner: \S+\s*\n\s+repo: \S+\s*\n\s+releaseType: draft\s*\n\s+private: false/m', $yml);
        $this->assertDoesNotMatchRegularExpression('/token\s*:/i', $yml);
        $this->assertDoesNotMatchRegularExpression('/gh[pousr]_[A-Za-z0-9]{20,}|github_pat_/', $yml);
        $this->assertStringContainsString('artifactName: ViLoCare-Setup-${version}.${ext}', $yml); // no spaces: matches latest.yml on GitHub
    }

    public function test_updater_defaults_and_dev_only_test_feed(): void
    {
        $updater = $this->electronFile('src/updater.js');

        $this->assertStringContainsString('autoDownload = true', $updater);
        $this->assertStringContainsString('autoInstallOnAppQuit = true', $updater);
        $this->assertStringContainsString('allowDowngrade = false', $updater);
        $this->assertStringContainsString('disableDifferentialDownload = false', $updater);
        $this->assertStringContainsString('4 * 60 * 60 * 1000', $updater);
        // The local test feed is read only when the app is NOT packaged.
        $this->assertMatchesRegularExpression('/if \(!app\.isPackaged\) \{[^}]*VILOCARE_UPDATE_TEST_URL|if \(!app\.isPackaged\) \{.*?testUrl/s', $updater);
        $this->assertStringContainsString('only honoured in unpackaged development runs', $updater);
    }

    public function test_installed_builds_have_no_devtools_and_the_bridge_checks_its_sender(): void
    {
        $main = $this->electronFile('src/main.js');

        $this->assertStringContainsString('const devTools = !p.packaged;', $main);
        $this->assertSame(2, substr_count($main, 'devTools,'));
        $this->assertStringContainsString('preload-app.js', $main);
        $this->assertSame(2, substr_count($main, 'policy.isFromAppWindow('));
        $this->assertStringContainsString('policy.verifyApprovalToken(token, updateSecret)', $main);
        // The installer is never started before PHP and the scheduler were stopped.
        $this->assertMatchesRegularExpression('/stopEverything\(\);[^\n]*\n[^\n]*\n\s*updater\.quitAndInstall\(\)/', $main);
        // Never install while Windows is shutting down.
        $this->assertStringContainsString('updater.skipInstallOnQuit();', $main);
    }

    public function test_installer_stops_php_inside_the_install_folder_and_never_touches_the_data_folder(): void
    {
        $nsh = $this->electronFile('build/installer.nsh');

        $this->assertStringContainsString('!macro customInit', $nsh);
        $this->assertStringContainsString('resources\\php\\*', $nsh);
        $this->assertStringNotContainsString('RMDir', $nsh);
        $this->assertStringNotContainsString('Delete ', $nsh);
        $this->assertStringContainsString('deleteAppDataOnUninstall: false', $this->electronFile('electron-builder.yml'));
    }

    public function test_build_script_never_publishes_and_prereleases_get_their_own_channel(): void
    {
        $package = json_decode($this->electronFile('package.json'), true);
        $build = $this->electronFile('scripts/build-installer.js');

        $this->assertStringContainsString('build-installer.js', $package['scripts']['build:installer']);
        $this->assertStringContainsString("'--publish', 'never'", $build);
        $this->assertStringContainsString('-c.publish.channel=', $build);
        $this->assertSame('node scripts/release.js', $package['scripts']['release']);
    }

    public function test_release_script_enforces_its_preconditions(): void
    {
        $release = $this->electronFile('scripts/release.js');

        foreach (['GH_TOKEN is not set', 'REPLACE_WITH', 'working tree has uncommitted changes', 'is not higher than the existing release', "'artisan', 'test'", 'draft: !prerelease'] as $needle) {
            $this->assertStringContainsString($needle, $release);
        }
        $this->assertStringNotContainsString('console.log(token', $release);
        $this->assertStringNotContainsString('writeFile', $release); // the token is never written anywhere
    }
}
