<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\LocationIds;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Safely reassigns any existing patient/sample records from old Uganda/Kenya locations
     * to South Sudan (Kapoeta Civil Hospital), then removes legacy locations.
     */
    public function up(): void
    {
        DB::transaction(function () {
            // 1. Identify legacy Uganda & Kenya location names
            $legacyStateNames = [
                'Kampala Metropolitan', 'Central Region', 'Eastern Region',
                'Nairobi Metropolitan', 'Coast Region',
            ];

            $legacyCountyNames = [
                'Jinja District', 'Kampala Central', 'Kawempe Division', 'Wakiso District',
                'Nairobi Central', 'Westlands Sub-County', 'Kiambu County', 'Mombasa County',
            ];

            $legacyFacilityNames = [
                'ViLoCare Main ART Centre', 'City Health Comprehensive Clinic',
                'Kawempe ART Community Centre', 'Wakiso Health Centre IV ART Unit',
                'Entebbe General Hospital ART Clinic', 'Jinja Regional Referral ART Centre',
                'Westlands ART Community Centre', 'Kiambu Referral ART Unit',
                'Thika District ART Clinic', 'Coast General ART Care Centre',
            ];

            // If no legacy locations exist (e.g. fresh database or testing), skip
            $hasLegacy = DB::table('states')->whereIn('name', $legacyStateNames)->exists()
                || DB::table('counties')->whereIn('name', $legacyCountyNames)->exists()
                || DB::table('facilities')->whereIn('name', $legacyFacilityNames)->exists();

            if (! $hasLegacy) {
                return;
            }

            // 2. Verify/ensure South Sudan default targets exist
            $easternEquatoria = DB::table('states')->where('name', 'Eastern Equatoria')->first();
            if (! $easternEquatoria) {
                $eesId = LocationIds::state('EES');
                DB::table('states')->insert([
                    'id' => $eesId,
                    'name' => 'Eastern Equatoria',
                    'code' => 'EES',
                    'sync_status' => 'synced',
                    'last_modified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $easternEquatoria = DB::table('states')->where('id', $eesId)->first();
            }

            $kapoetaSouthCounty = DB::table('counties')
                ->where('name', 'Kapoeta South County')
                ->where('state_id', $easternEquatoria->id)
                ->first();

            if (! $kapoetaSouthCounty) {
                $kpsId = LocationIds::county('EES', 'KPS');
                DB::table('counties')->insert([
                    'id' => $kpsId,
                    'state_id' => $easternEquatoria->id,
                    'name' => 'Kapoeta South County',
                    'code' => 'KPS',
                    'sync_status' => 'synced',
                    'last_modified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $kapoetaSouthCounty = DB::table('counties')->where('id', $kpsId)->first();
            }

            $kapoetaHospital = DB::table('facilities')
                ->where('name', 'Kapoeta Civil Hospital')
                ->first();

            if (! $kapoetaHospital) {
                $facId = LocationIds::facility('KCH-001');
                DB::table('facilities')->insert([
                    'id' => $facId,
                    'county_id' => $kapoetaSouthCounty->id,
                    'name' => 'Kapoeta Civil Hospital',
                    'code' => 'KCH-001',
                    'facility_level' => 'Civil Hospital / ART Clinic',
                    'sync_status' => 'synced',
                    'last_modified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $kapoetaHospital = DB::table('facilities')->where('id', $facId)->first();
            }

            // 3. Collect IDs of legacy rows
            $legacyFacilityIds = DB::table('facilities')
                ->whereIn('name', $legacyFacilityNames)
                ->pluck('id')
                ->toArray();

            $legacyCountyIds = DB::table('counties')
                ->whereIn('name', $legacyCountyNames)
                ->pluck('id')
                ->toArray();

            $legacyStateIds = DB::table('states')
                ->whereIn('name', $legacyStateNames)
                ->pluck('id')
                ->toArray();

            // 4. Safely reassign any patient records referencing legacy locations
            if (Schema::hasTable('patients')) {
                if (! empty($legacyFacilityIds)) {
                    DB::table('patients')
                        ->whereIn('facility_id', $legacyFacilityIds)
                        ->update([
                            'facility_id' => $kapoetaHospital->id,
                            'county_id' => $kapoetaSouthCounty->id,
                            'state_id' => $easternEquatoria->id,
                            'updated_at' => now(),
                        ]);
                }

                if (! empty($legacyCountyIds)) {
                    DB::table('patients')
                        ->whereIn('county_id', $legacyCountyIds)
                        ->update([
                            'county_id' => $kapoetaSouthCounty->id,
                            'state_id' => $easternEquatoria->id,
                            'updated_at' => now(),
                        ]);
                }

                if (! empty($legacyStateIds)) {
                    DB::table('patients')
                        ->whereIn('state_id', $legacyStateIds)
                        ->update([
                            'state_id' => $easternEquatoria->id,
                            'updated_at' => now(),
                        ]);
                }
            }

            // 5. Safely reassign any sample collections referencing legacy facilities
            if (Schema::hasTable('sample_collections') && ! empty($legacyFacilityIds)) {
                DB::table('sample_collections')
                    ->whereIn('facility_id', $legacyFacilityIds)
                    ->update([
                        'facility_id' => $kapoetaHospital->id,
                        'updated_at' => now(),
                    ]);
            }

            // 6. Delete legacy facilities, counties, states
            if (! empty($legacyFacilityIds)) {
                DB::table('facilities')->whereIn('id', $legacyFacilityIds)->delete();
            }
            DB::table('facilities')->whereIn('name', $legacyFacilityNames)->delete();

            if (! empty($legacyCountyIds)) {
                DB::table('counties')->whereIn('id', $legacyCountyIds)->delete();
            }
            DB::table('counties')->whereIn('name', $legacyCountyNames)->delete();

            if (! empty($legacyStateIds)) {
                DB::table('states')->whereIn('id', $legacyStateIds)->delete();
            }
            DB::table('states')->whereIn('name', $legacyStateNames)->delete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Permanent cleanup; no reversal needed
    }
};
