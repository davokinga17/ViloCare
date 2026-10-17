<?php

use App\Support\TestingLaboratoryCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Testing laboratories become their own reference table, independent of facilities.
 *
 *  1. Refuse (before changing anything) if the NPHL-017 "facility" is referenced by any record.
 *  2. Create testing_laboratories and seed the 7 laboratories (same deterministic ids everywhere).
 *  3. Remove the NPHL-017 facility, which only existed so the lab could appear in a dropdown.
 *  4. Drop facilities.has_testing_lab: the new table is the single source of truth.
 *
 * Safe to run twice and on both SQLite and PostgreSQL. Deletion is not synced, so this runs on the
 * cloud and on every clinic; sync.retired_locations stops a later pull from bringing the row back.
 */
return new class extends Migration
{
    private const RETIRED_FACILITY_CODE = 'NPHL-017';

    public function up(): void
    {
        // 1. Nothing is touched until we know the facility can go.
        $facility = DB::table('facilities')->where('code', self::RETIRED_FACILITY_CODE)->first();
        if ($facility) {
            $references = $this->references($facility);
            if ($references !== []) {
                throw new RuntimeException(
                    'Cannot remove facility '.self::RETIRED_FACILITY_CODE.' ('.$facility->name.'): it is still referenced by '
                    .implode(', ', array_map(fn ($count, $where) => "{$count} row(s) in {$where}", $references, array_keys($references)))
                    .'. Move or correct those records first, then run the migration again. No changes were made.'
                );
            }
        }

        // 2. The laboratories table and its rows.
        if (! Schema::hasTable('testing_laboratories')) {
            Schema::create('testing_laboratories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('code')->unique();
                $table->uuid('facility_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('sync_status')->default('synced');
                $table->timestamp('last_modified_at')->nullable();
                $table->timestamps();

                $table->foreign('facility_id')->references('id')->on('facilities')->onDelete('set null');
            });
        }

        TestingLaboratoryCatalog::sync();

        // 3. Retire the facility.
        if ($facility) {
            DB::table('facilities')->where('id', $facility->id)->delete();
        }

        // 4. One source of truth for "is a lab".
        if (Schema::hasColumn('facilities', 'has_testing_lab')) {
            Schema::table('facilities', function (Blueprint $table) {
                $table->dropColumn('has_testing_lab');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: the removed facility and flag are not restored, and laboratory
        // rows may already be synced. Roll forward instead.
    }

    /**
     * Every row that points at the facility, as "table.column" => count. Discovered from the schema
     * so a table added later is still checked.
     *
     * @return array<string, int>
     */
    private function references(object $facility): array
    {
        $found = [];

        foreach (Schema::getTableListing(null, false) as $table) {
            if (in_array($table, ['facilities', 'testing_laboratories', 'migrations'], true)) {
                continue;
            }

            if (Schema::hasColumn($table, 'facility_id')) {
                $count = DB::table($table)->where('facility_id', $facility->id)->count();
                if ($count > 0) {
                    $found["{$table}.facility_id"] = $count;
                }
            }

            if (Schema::hasColumn($table, 'health_facility_code')) {
                $count = DB::table($table)->where('health_facility_code', $facility->code)->count();
                if ($count > 0) {
                    $found["{$table}.health_facility_code"] = $count;
                }
            }
        }

        return $found;
    }
};
