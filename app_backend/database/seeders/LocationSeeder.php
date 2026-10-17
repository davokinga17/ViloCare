<?php

namespace Database\Seeders;

use App\Support\LocationIds;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $stateIdColumn = $this->column('states', ['state_id', 'id']);
        $stateNameColumn = $this->column('states', ['state_name', 'name']);
        $countyIdColumn = $this->column('counties', ['county_id', 'id']);
        $countyNameColumn = $this->column('counties', ['county_name', 'name']);
        $facilityNameColumn = $this->column('facilities', ['facility_name', 'name']);

        $statesHaveTimestamps = Schema::hasColumn('states', 'created_at') && Schema::hasColumn('states', 'updated_at');
        $countiesHaveTimestamps = Schema::hasColumn('counties', 'created_at') && Schema::hasColumn('counties', 'updated_at');
        $facilitiesHaveTimestamps = Schema::hasColumn('facilities', 'created_at') && Schema::hasColumn('facilities', 'updated_at');

        if (! $stateIdColumn || ! $stateNameColumn || ! $countyIdColumn || ! $countyNameColumn || ! $facilityNameColumn) {
            return;
        }

        $states = [
            'Central Equatoria' => 'CES',
            'Eastern Equatoria' => 'EES',
            'Western Equatoria' => 'WES',
            'Jonglei' => 'JON',
            'Lakes' => 'LAK',
            'Unity' => 'UNI',
            'Upper Nile' => 'UNS',
            'Warrap' => 'WAR',
            'Northern Bahr el Ghazal' => 'NBG',
            'Western Bahr el Ghazel' => 'WBG',
            'Greater Pibor Administrative Area (GPAA)' => 'GPAA',
            'Abyei Administrative Area' => 'AAA',
            'Ruweng Administrative Area' => 'RAA',
        ];

        foreach ($states as $state => $code) {
            $existing = DB::table('states')->where('code', $code)->first() ?? DB::table('states')->where($stateNameColumn, $state)->first();

            $values = $statesHaveTimestamps
                ? ['updated_at' => $now, 'created_at' => $now]
                : [];

            if (Schema::hasColumn('states', 'sync_status')) {
                $values['sync_status'] = 'synced';
            }
            if (Schema::hasColumn('states', 'last_modified_at')) {
                $values['last_modified_at'] = $now;
            }
            if (Schema::hasColumn('states', 'code')) {
                $values['code'] = $code;
            }
            if (! $existing && Schema::hasColumn('states', 'id')) {
                $values['id'] = LocationIds::state($code);
            }

            DB::table('states')->updateOrInsert(
                $existing ? [$stateIdColumn => $existing->{$stateIdColumn}] : [$stateNameColumn => $state],
                $values
            );
        }

        $easternEquatoriaId = DB::table('states')
            ->where($stateNameColumn, 'Eastern Equatoria')
            ->value($stateIdColumn);

        $counties = [
            'Budi County' => 'BUD',
            'Ikotos County' => 'IKO',
            'Kapoeta East County' => 'KPE',
            'Kapoeta North County' => 'KPN',
            'Kapoeta South County' => 'KPS',
            'Lafon County' => 'LAF',
            'Magwi County' => 'MAG',
            'Torit County' => 'TOR',
            'Juba County' => 'JUB',
        ];

        $centralEquatoriaId = DB::table('states')
            ->where($stateNameColumn, 'Central Equatoria')
            ->value($stateIdColumn);

        // Counties outside Eastern Equatoria (default state for the rest).
        $countyStateIds = [
            'Juba County' => $centralEquatoriaId,
        ];

        foreach ($counties as $county => $code) {
            $countyStateId = $countyStateIds[$county] ?? $easternEquatoriaId;
            $countyStateCode = isset($countyStateIds[$county]) ? 'CES' : 'EES';
            $existing = DB::table('counties')->where('state_id', $countyStateId)->where('code', $code)->first()
                ?? DB::table('counties')->where($countyNameColumn, $county)->first();

            $values = [
                'state_id' => $countyStateId,
            ];

            if ($countiesHaveTimestamps) {
                $values['updated_at'] = $now;
                $values['created_at'] = $now;
            }
            if (Schema::hasColumn('counties', 'sync_status')) {
                $values['sync_status'] = 'synced';
            }
            if (Schema::hasColumn('counties', 'last_modified_at')) {
                $values['last_modified_at'] = $now;
            }
            if (Schema::hasColumn('counties', 'code')) {
                $values['code'] = $code;
            }
            if (! $existing && Schema::hasColumn('counties', 'id')) {
                $values['id'] = LocationIds::county($countyStateCode, $code);
            }

            DB::table('counties')->updateOrInsert(
                $existing ? [$countyIdColumn => $existing->{$countyIdColumn}] : [$countyNameColumn => $county],
                $values
            );
        }

        $countyIds = DB::table('counties')->pluck($countyIdColumn, $countyNameColumn);

        $facilities = [
            'Nimule Hospital' => ['county' => 'Magwi County', 'code' => 'NMH-001', 'level' => 'Hospital'],
            'Pageri PHCC' => ['county' => 'Magwi County', 'code' => 'PGP-002', 'level' => 'PHCC'],
            'Abara PHCC' => ['county' => 'Magwi County', 'code' => 'ABP-003', 'level' => 'PHCC'],
            'Magwi PHCC' => ['county' => 'Magwi County', 'code' => 'MGP-004', 'level' => 'PHCC'],
            'Owinykibul PHCC' => ['county' => 'Magwi County', 'code' => 'OWP-005', 'level' => 'PHCC'],
            'Obbo PHCC' => ['county' => 'Magwi County', 'code' => 'OBP-006', 'level' => 'PHCC'],
            'Pajok PHCC' => ['county' => 'Magwi County', 'code' => 'PJP-007', 'level' => 'PHCC'],
            'Lobone PHCC' => ['county' => 'Magwi County', 'code' => 'LBP-008', 'level' => 'PHCC'],
            'Torit State Hospital' => ['county' => 'Torit County', 'code' => 'TSH-009', 'level' => 'State Hospital'],
            'Nyong PHCC' => ['county' => 'Torit County', 'code' => 'NYP-010', 'level' => 'PHCC'],
            'Hiyala PHCC' => ['county' => 'Torit County', 'code' => 'HYP-011', 'level' => 'PHCC'],
            'St. Theresa Mission Hospital Isohe' => ['county' => 'Torit County', 'code' => 'STMH-012', 'level' => 'Mission Hospital'],
            'Kapoeta Civil Hospital' => ['county' => 'Kapoeta South County', 'code' => 'KCH-001', 'level' => 'Civil Hospital / ART Clinic'],
            'Naknak PHCC' => ['county' => 'Kapoeta South County', 'code' => 'NKP-014', 'level' => 'PHCC'],
            'Riwoto PHCC' => ['county' => 'Kapoeta North County', 'code' => 'RWP-015', 'level' => 'PHCC'],
            'Narus PHCC' => ['county' => 'Kapoeta East County', 'code' => 'NRP-016', 'level' => 'PHCC'],
        ];

        foreach ($facilities as $facility => $info) {
            $county = $info['county'];
            $existing = DB::table('facilities')->where('code', $info['code'])->first()
                ?? DB::table('facilities')->where($facilityNameColumn, $facility)->first();

            $values = [
                'county_id' => $countyIds[$county] ?? null,
            ];

            if (Schema::hasColumn('facilities', 'state_id')) {
                $values['state_id'] = $countyStateIds[$county] ?? $easternEquatoriaId;
            }
            if (Schema::hasColumn('facilities', 'code')) {
                $values['code'] = $info['code'];
            }
            if (Schema::hasColumn('facilities', 'facility_level')) {
                $values['facility_level'] = $info['level'];
            }
            if (Schema::hasColumn('facilities', 'sync_status')) {
                $values['sync_status'] = 'synced';
            }
            if (Schema::hasColumn('facilities', 'last_modified_at')) {
                $values['last_modified_at'] = $now;
            }
            if ($facilitiesHaveTimestamps) {
                $values['updated_at'] = $now;
                $values['created_at'] = $now;
            }
            if (! $existing && Schema::hasColumn('facilities', 'id')) {
                $values['id'] = LocationIds::facility($info['code']);
            }

            DB::table('facilities')->updateOrInsert(
                $existing ? ['id' => $existing->id] : [$facilityNameColumn => $facility],
                $values
            );
        }

        // Deliberately no cleanup here. This seeder used to delete every location not in its list
        // and move the patients and samples that pointed at them to Kapoeta Civil Hospital. That
        // erased real data and (on a clinic) rows the cloud added. Locations are now only ever
        // added or updated by business code; the cloud decides what exists (see LocationReconciler).

        // Testing laboratories are their own table (not facilities); link them to the facilities above.
        $this->call(TestingLaboratorySeeder::class);
    }

    private function column(string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }
}
