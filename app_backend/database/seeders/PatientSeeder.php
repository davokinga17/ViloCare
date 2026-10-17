<?php

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Seeder;

class PatientSeeder extends Seeder
{
    public function run(): void
    {
        $facility = Facility::where('name', 'Kapoeta Civil Hospital')->first() ?? Facility::first();
        $clinician = User::where('role', 'clinician')->first() ?? User::first();

        if (!$facility || !$clinician) {
            return;
        }

        $patientsData = [
            [
                'id' => 'a752162d-8c63-4423-b89d-284d29ba3313',
                'art_number' => 'ART-10001',
                'first_name' => 'John',
                'last_name' => 'Kamau',
                'sex' => 'male',
                'age' => 38,
                'phone' => '+211910000001',
                'address' => 'Kapoeta South, Hai Commercial',
                'art_start_date' => '2021-04-12',
                'is_pregnant' => false,
                'is_breastfeeding' => false,
                'regimen' => 'TDF + 3TC + DTG (TLD)',
                'regimen_line' => '1st Line',
            ],
            [
                'id' => '2a400558-e086-4aa6-ba80-15496907ef71',
                'art_number' => 'ART-10002',
                'first_name' => 'Grace',
                'last_name' => 'Wanjiku',
                'sex' => 'female',
                'age' => 27,
                'phone' => '+211910000002',
                'address' => 'Kapoeta Town, Block 2',
                'art_start_date' => '2023-09-01',
                'is_pregnant' => true,
                'is_breastfeeding' => false,
                'regimen' => 'TDF + 3TC + DTG (TLD)',
                'regimen_line' => '1st Line',
            ],
            [
                'id' => 'ac2aaf5f-7cb5-497e-aea5-0e028d73a332',
                'art_number' => 'ART-10003',
                'first_name' => 'Amina',
                'last_name' => 'Abdi',
                'sex' => 'female',
                'age' => 24,
                'phone' => '+211910000003',
                'address' => 'Hai Machakos, Kapoeta',
                'art_start_date' => '2022-11-20',
                'is_pregnant' => false,
                'is_breastfeeding' => true,
                'regimen' => 'TDF + 3TC + DTG (TLD)',
                'regimen_line' => '1st Line',
            ],
            [
                'id' => 'dceac4e9-d42a-41ce-9ee8-e50083e189c5',
                'art_number' => 'ART-10004',
                'first_name' => 'Kevin',
                'last_name' => 'Otieno',
                'sex' => 'male',
                'age' => 8, // Pediatric < 15
                'phone' => '+211910000004',
                'address' => 'Lopet, Kapoeta South',
                'art_start_date' => '2024-01-10',
                'is_pregnant' => false,
                'is_breastfeeding' => false,
                'regimen' => 'ABC + 3TC + DTG (Pediatric)',
                'regimen_line' => '1st Line',
            ],
            [
                'id' => 'd1442e30-cb47-4a52-a6bc-e67443fa93e3',
                'art_number' => 'ART-10005',
                'first_name' => 'Moses',
                'last_name' => 'Kipchumba',
                'sex' => 'male',
                'age' => 45,
                'phone' => '+211910000005',
                'address' => 'Kapoeta Central Residential',
                'art_start_date' => '2019-06-15',
                'is_pregnant' => false,
                'is_breastfeeding' => false,
                'regimen' => 'AZT + 3TC + ATV/r',
                'regimen_line' => '2nd Line',
            ],
        ];

        foreach ($patientsData as $pData) {
            $patient = Patient::updateOrCreate(
                ['art_number' => $pData['art_number']],
                [
                    'id' => $pData['id'],
                    'first_name' => $pData['first_name'],
                    'last_name' => $pData['last_name'],
                    'sex' => $pData['sex'],
                    'age' => $pData['age'],
                    'phone' => $pData['phone'],
                    'address' => $pData['address'],
                    'art_start_date' => $pData['art_start_date'],
                    'is_pregnant' => $pData['is_pregnant'],
                    'is_breastfeeding' => $pData['is_breastfeeding'],
                    'facility_id' => $facility->id,
                    'county_id' => $facility->county_id,
                    'state_id' => $facility->county->state_id,
                    'status' => 'active',
                    'created_by' => $clinician->id,
                    'sync_status' => 'synced',
                    'last_modified_at' => now(),
                ]
            );

            if ($patient->regimenHistories()->count() === 0) {
                $patient->assignNewRegimen(
                    regimenName: $pData['regimen'],
                    regimenLine: $pData['regimen_line'],
                    startDate: $pData['art_start_date'],
                    reason: 'Baseline regimen recorded during enrollment',
                    userId: $clinician->id
                );
            }
        }
    }
}
