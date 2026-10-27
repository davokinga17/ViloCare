<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DesktopPerformanceConfigTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin@example.org', 'role' => UserRole::ADMINISTRATOR,
            'password' => 'Password-12345', 'must_change_password' => false, 'is_active' => true,
        ]);
    }

    private function electronFile(string $relative): string
    {
        $file = base_path('../electron/'.$relative);
        if (! is_file($file)) {
            $this->markTestSkipped('The Electron shell is not part of this checkout.');
        }

        return file_get_contents($file);
    }

    // ---- nothing is loaded from the internet ----

    public function test_pages_reference_no_external_hosts(): void
    {
        $pages = [
            $this->get(route('login'))->getContent(),
            $this->actingAs($this->admin())->get(route('dashboard'))->getContent(),
            $this->get(route('patients.index'))->getContent(),
            $this->get(route('sync.index'))->getContent(),
            $this->get(route('viral-load.index'))->getContent(),
        ];

        foreach ($pages as $html) {
            preg_match_all('/<(?:script|link|img)\b[^>]*\b(?:src|href)=["\'](https?:)?\/\/(?!127\.0\.0\.1|localhost)([^"\'\/]+)/i', $html, $m);
            $this->assertSame([], $m[2], 'External hosts referenced: '.implode(', ', $m[2]));
            $this->assertStringNotContainsString('fonts.googleapis.com', $html);
            $this->assertStringNotContainsString('document.write', $html);
        }
    }

    public function test_manrope_is_served_locally_with_font_display_swap(): void
    {
        $css = file_get_contents(public_path('css/fonts.css'));

        $this->assertStringContainsString("font-family: 'Manrope'", $css);
        $this->assertSame(substr_count($css, '@font-face'), substr_count($css, 'font-display: swap'));
        $this->assertStringNotContainsString('http', preg_replace('/\/\*.*?\*\//s', '', $css));

        preg_match_all("/url\('\.\.\/(fonts\/[^']+)'\)/", $css, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $file) {
            $this->assertFileExists(public_path($file));
            $this->assertGreaterThan(5000, filesize(public_path($file)));
        }

        $html = $this->get(route('login'))->getContent();
        $this->assertStringContainsString('css/fonts.css', $html);
    }

    // ---- SQLite: WAL + short busy timeout (set by the desktop shell) ----

    public function test_sqlite_connection_applies_wal_and_busy_timeout_from_the_environment(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vlc-wal-');
        Env::getRepository()->set('DB_JOURNAL_MODE', 'WAL');
        Env::getRepository()->set('DB_BUSY_TIMEOUT', '5000');
        Env::getRepository()->set('DB_SYNCHRONOUS', 'NORMAL');

        try {
            $config = (require config_path('database.php'))['connections']['sqlite'];
            $this->assertSame('WAL', $config['journal_mode']);
            $this->assertSame(5000, $config['busy_timeout']);
            $this->assertSame('NORMAL', $config['synchronous']);

            config(['database.connections.wal_test' => array_merge($config, ['database' => $file, 'url' => null])]);
            $db = DB::connection('wal_test');
            $this->assertSame('wal', strtolower($db->selectOne('pragma journal_mode')->journal_mode));
            $this->assertSame(5000, (int) $db->selectOne('pragma busy_timeout')->timeout);
            $this->assertSame(1, (int) $db->selectOne('pragma synchronous')->synchronous); // 1 = NORMAL
        } finally {
            Env::getRepository()->clear('DB_JOURNAL_MODE');
            Env::getRepository()->clear('DB_BUSY_TIMEOUT');
            Env::getRepository()->clear('DB_SYNCHRONOUS');
            DB::purge('wal_test');
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($file.$suffix);
            }
        }
    }

    public function test_defaults_are_unchanged_when_the_environment_does_not_set_them(): void
    {
        $config = (require config_path('database.php'))['connections']['sqlite'];

        $this->assertNull($config['journal_mode']);
        $this->assertNull($config['busy_timeout']);
        $this->assertNull($config['synchronous']);
    }

    // ---- desktop shell wiring ----

    public function test_desktop_shell_moves_sessions_and_cache_to_files_and_enables_wal(): void
    {
        $php = $this->electronFile('src/php.js');

        $this->assertMatchesRegularExpression("/SESSION_DRIVER: 'file'/", $php);
        $this->assertMatchesRegularExpression("/CACHE_STORE: 'file'/", $php);
        $this->assertMatchesRegularExpression("/DB_JOURNAL_MODE: 'WAL'/", $php);
        $this->assertMatchesRegularExpression("/DB_BUSY_TIMEOUT: '\d{3,4}'/", $php);
        // The session/cache folders live in the data folder and are created before PHP starts.
        $this->assertStringContainsString("'framework', 'sessions'", $php);
        $this->assertStringContainsString("'framework', 'cache', 'data'", $php);
        // Backups must fold the WAL into the main file first.
        $this->assertStringContainsString('wal_checkpoint(TRUNCATE)', $php);
        $this->assertStringContainsString('checkpointDatabase()', $this->electronFile('src/main.js'));
    }

    public function test_installer_lets_every_windows_account_write_the_data_folder_including_existing_files(): void
    {
        $nsh = $this->electronFile('build/installer.nsh');

        $this->assertStringContainsString('*S-1-5-32-545:(OI)(CI)M', $nsh);
        $this->assertStringContainsString('/T', $nsh);
    }

    public function test_bundled_php_ships_opcache_with_production_settings_and_jit_off(): void
    {
        $script = $this->electronFile('scripts/fetch-php.js');

        $this->assertStringContainsString("'php_opcache.dll'", $script); // no longer pruned
        $this->assertStringContainsString("'zend_extension=php_opcache.dll'", $script);
        $this->assertStringContainsString("'opcache.enable = 1'", $script);
        $this->assertStringContainsString("'opcache.validate_timestamps = 0'", $script);
        $this->assertStringContainsString("'opcache.jit = disable'", $script);
        $this->assertStringContainsString("'opcache.jit_buffer_size = 0'", $script);
    }

    public function test_startup_self_check_logs_whether_opcache_is_enabled(): void
    {
        $router = $this->electronFile('resources/router.php');
        $php = $this->electronFile('src/php.js');
        $main = $this->electronFile('src/main.js');

        // The check runs inside the web server process, answers only to this computer, and is logged at every start.
        $this->assertStringContainsString("'/__vilocare/opcache'", $router);
        $this->assertStringContainsString("['127.0.0.1', '::1']", $router);
        $this->assertStringContainsString('opcache_get_status', $router);
        $this->assertStringContainsString('opcache self-check: ENABLED', $php);
        $this->assertStringContainsString('opcache self-check: WARNING DISABLED', $php);
        $this->assertStringContainsString('php.opcacheSelfCheck(port).then(log', $main);
    }

    public function test_desktop_version_is_1_1_2_in_package_and_lockfile(): void
    {
        $package = json_decode($this->electronFile('package.json'), true);
        $lock = json_decode($this->electronFile('package-lock.json'), true);

        $this->assertSame('1.1.2', $package['version']);
        $this->assertSame('1.1.2', $lock['version']);
        $this->assertSame('1.1.2', $lock['packages']['']['version']);
    }

    public function test_installer_staging_never_packs_sqlite_files_or_their_copies(): void
    {
        $script = $this->electronFile('scripts/prepare-app.js');

        $this->assertStringContainsString("/\\.(sqlite|db)($|[-.])/", $script);
        // The same pattern, applied to the names that must (not) be packed.
        $pattern = '/\.(sqlite|db)($|[-.])/';
        foreach (['database.sqlite', 'database.sqlite.bak', 'database.sqlite-wal', 'database.sqlite-shm', 'old.db'] as $name) {
            $this->assertSame(1, preg_match($pattern, $name), "{$name} must be excluded");
        }
        foreach (['.gitignore', '2026_10_13_000001_add_latest_result_index_to_viral_load_results.php'] as $name) {
            $this->assertSame(0, preg_match($pattern, $name), "{$name} must be kept");
        }
    }
}