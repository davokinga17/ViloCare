<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ViralLoadIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = 'viral_load_results_latest_result_index';

    public function test_composite_index_exists_and_matches_the_latest_result_ordering(): void
    {
        $this->assertTrue(Schema::hasIndex('viral_load_results', self::INDEX));

        $columns = collect(Schema::getIndexes('viral_load_results'))->firstWhere('name', self::INDEX)['columns'];
        $this->assertSame(['patient_id', 'sample_date', 'created_at', 'id'], $columns);
    }

    public function test_migration_is_reversible_and_safe_to_run_twice(): void
    {
        $migration = require database_path('migrations/2026_10_13_000001_add_latest_result_index_to_viral_load_results.php');

        $migration->down();
        $this->assertFalse(Schema::hasIndex('viral_load_results', self::INDEX));

        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('viral_load_results', self::INDEX));
    }
}