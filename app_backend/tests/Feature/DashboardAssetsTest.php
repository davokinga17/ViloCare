<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\AssetVersion;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard is styled by plain CSS files in public/css (not by Tailwind: the app layout never loads the Vite
 * CSS). These tests keep that CSS and its markup in step, and keep browsers from reusing an old copy after a deploy.
 */
class DashboardAssetsTest extends TestCase
{
    use RefreshDatabase;

    private function css(): string
    {
        return (string) file_get_contents(public_path('css/dashboard.css'));
    }

    public function test_dashboard_assets_are_linked_with_a_version_that_changes_with_the_file(): void
    {
        $this->seed(LocationSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.org', 'role' => UserRole::ADMINISTRATOR, 'password' => 'Password-12345', 'must_change_password' => false, 'is_active' => true]);

        $page = $this->actingAs($admin)->get('/dashboard')->assertOk();

        foreach (['css/dashboard.css', 'js/dashboard-charts.js', 'js/chart.umd.min.js'] as $path) {
            $version = substr((string) md5_file(public_path($path)), 0, 10);
            $page->assertSee($path.'?v='.$version, false);
            $this->assertSame($version, AssetVersion::hash($path));
        }
        $this->assertSame('0', AssetVersion::hash('css/does-not-exist.css'));
    }

    public function test_every_chart_class_in_the_dashboard_view_is_defined_in_dashboard_css(): void
    {
        $blade = (string) file_get_contents(resource_path('views/dashboard/index.blade.php'));
        preg_match_all('/class="([^"]*)"/', $blade, $attributes);

        $classes = collect($attributes[1])
            ->flatMap(fn ($attr) => preg_split('/\s+/', trim(preg_replace('/\{\{.*?\}\}|@\w+(\(.*?\))?/', ' ', $attr))))
            ->filter(fn ($c) => preg_match('/^(chart|donut|legend)-/', $c) || $c === 'insight-card')
            ->unique()->values();

        $this->assertNotEmpty($classes);
        foreach ($classes as $class) {
            $this->assertMatchesRegularExpression('/\.'.preg_quote($class, '/').'(?![\w-])/', $this->css(), "Class {$class} is used in the dashboard view but not defined in public/css/dashboard.css");
        }
    }

    public function test_dashboard_css_keeps_the_two_row_grid_fixed_heights_and_the_stacking_rule(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\.chart-row-top\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*2fr\)\s*minmax\(0,\s*1fr\)/', $css);
        $this->assertMatchesRegularExpression('/\.chart-row-bottom\s*\{[^}]*grid-template-columns:\s*repeat\(3,/', $css);
        $this->assertMatchesRegularExpression('/\.chart-row-top \.chart-card\s*\{[^}]*height:\s*\d+px/', $css);
        $this->assertMatchesRegularExpression('/\.chart-row-bottom \.chart-card\s*\{[^}]*height:\s*\d+px/', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 900px\)\s*\{[^@]*\.chart-row-top,\s*\.chart-row-bottom\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/', $css);
        $this->assertMatchesRegularExpression('/\.chart-caption\s*\{[^}]*font-size:/', $css);
    }

    public function test_tailwind_only_reads_files_under_resources_so_docker_and_local_builds_match(): void
    {
        $appCss = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("@import 'tailwindcss' source(none);", $appCss);
        preg_match_all("/@source\s+'([^']+)'/", $appCss, $sources);
        $this->assertNotEmpty($sources[1]);
        foreach ($sources[1] as $path) {
            $this->assertMatchesRegularExpression('#^\.\./(views|js)(/|$)#', $path, "@source {$path} points outside resources/");
        }
    }
}
