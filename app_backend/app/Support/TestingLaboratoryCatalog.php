<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The testing laboratories ViLoCare knows about, independent of the facility list.
 *
 * Shared by the migration and the seeder so a fresh database, a clinic and the cloud all end up
 * with the same rows and the same deterministic ids. A lab inside a facility links to it by the
 * facility's business code; the National Public Health Laboratory belongs to no facility.
 */
class TestingLaboratoryCatalog
{
    /** @var array<int, array{code: string, name: string, facility_code: ?string}> */
    public const LABS = [
        ['code' => 'KCH-001', 'name' => 'Kapoeta Civil Hospital', 'facility_code' => 'KCH-001'],
        ['code' => 'NRP-016', 'name' => 'Narus PHCC', 'facility_code' => 'NRP-016'],
        ['code' => 'STMH-012', 'name' => 'St. Theresa Mission Hospital Isohe', 'facility_code' => 'STMH-012'],
        ['code' => 'TSH-009', 'name' => 'Torit State Hospital', 'facility_code' => 'TSH-009'],
        ['code' => 'MGP-004', 'name' => 'Magwi PHCC', 'facility_code' => 'MGP-004'],
        ['code' => 'NMH-001', 'name' => 'Nimule Hospital', 'facility_code' => 'NMH-001'],
        ['code' => 'NPHL-017', 'name' => 'National Public Health Laboratory', 'facility_code' => null],
    ];

    /**
     * Inserts or updates the catalog by code. Re-running changes nothing. Existing rows keep their id,
     * their is_active choice and any facility link already set; a missing link is filled in when the
     * facility exists. Rows are written as 'synced': the cloud owns this table and clinics never push it.
     */
    public static function sync(): void
    {
        $now = now();

        foreach (self::LABS as $lab) {
            $facilityId = $lab['facility_code']
                ? DB::table('facilities')->where('code', $lab['facility_code'])->value('id')
                : null;

            $existing = DB::table('testing_laboratories')->where('code', $lab['code'])->first();

            if (! $existing) {
                DB::table('testing_laboratories')->insert([
                    'id' => LocationIds::laboratory($lab['code']),
                    'name' => $lab['name'],
                    'code' => $lab['code'],
                    'facility_id' => $facilityId,
                    'is_active' => true,
                    'sync_status' => 'synced',
                    'last_modified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            if ($existing->facility_id === null && $facilityId !== null) {
                DB::table('testing_laboratories')->where('id', $existing->id)->update([
                    'facility_id' => $facilityId,
                    'last_modified_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public static function tableExists(): bool
    {
        return Schema::hasTable('testing_laboratories');
    }
}
