<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Support\LocationIds;

return new class extends Migration
{
    private const FACILITY_NAME = 'National Public Health Laboratory';

    public function up(): void
    {
        DB::transaction(function () {
            $now = now();

            $state = DB::table('states')->where('code', 'CES')->first() ?? DB::table('states')->where('name', 'Central Equatoria')->first();
            $stateId = $state?->id;
            if (! $stateId) {
                $stateId = LocationIds::state('CES');
                DB::table('states')->insert([
                    'id' => $stateId,
                    'name' => 'Central Equatoria',
                    'code' => 'CES',
                    'sync_status' => 'pending',
                    'last_modified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $facility = DB::table('facilities')->where('code', 'NPHL-017')->first() ?? DB::table('facilities')->where('name', self::FACILITY_NAME)->first();

            if ($facility) {
                if (! $facility->has_testing_lab) {
                    DB::table('facilities')->where('id', $facility->id)->update([
                        'has_testing_lab' => true,
                        'sync_status' => 'pending',
                        'last_modified_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return;
            }

            $county = DB::table('counties')->where('state_id', $stateId)->where('code', 'JUB')->first() ?? DB::table('counties')->where('name', 'Juba County')->first();
            $countyId = $county?->id;
            if (! $countyId) {
                $countyId = LocationIds::county('CES', 'JUB');
                DB::table('counties')->insert([
                    'id' => $countyId,
                    'state_id' => $stateId,
                    'name' => 'Juba County',
                    'code' => 'JUB',
                    'sync_status' => 'pending',
                    'last_modified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('facilities')->insert([
                'id' => LocationIds::facility('NPHL-017'),
                'county_id' => $countyId,
                'name' => self::FACILITY_NAME,
                'code' => 'NPHL-017',
                'facility_level' => 'National Reference Laboratory',
                'has_testing_lab' => true,
                'sync_status' => 'pending',
                'last_modified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        // Facility may already be referenced by synced data; leave it in place.
    }
};
