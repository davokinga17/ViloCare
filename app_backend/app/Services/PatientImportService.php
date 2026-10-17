<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\County;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\State;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PatientImportService
{
    /**
     * Import patients from an uploaded CSV file with row-by-row validation.
     *
     * @return array{imported: int, failed: int, errors: array<array{row: int, art_number: string|null, errors: array<string>}>}
     */
    public function importCsv(UploadedFile $file, string $userId): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return [
                'imported' => 0,
                'failed' => 0,
                'errors' => [['row' => 0, 'art_number' => null, 'errors' => ['Could not read the uploaded CSV file.']]],
            ];
        }

        // Read header row
        $rawHeader = fgetcsv($handle);
        if (!$rawHeader) {
            fclose($handle);
            return [
                'imported' => 0,
                'failed' => 0,
                'errors' => [['row' => 1, 'art_number' => null, 'errors' => ['The CSV file is completely empty.']]],
            ];
        }

        // Normalize header keys: trim, lowercase, replace spaces with underscores
        $headers = array_map(fn ($h) => Str::snake(trim(strtolower((string) $h))), $rawHeader);

        // Preload default locations for fast resolution
        $defaultFacility = Facility::first();
        $defaultCounty = $defaultFacility?->county ?? County::first();
        $defaultState = $defaultCounty?->state ?? State::first();

        $facilitiesByCode = Facility::all()->keyBy('code');
        $countiesByCode = County::all()->keyBy('code');
        $statesByCode = State::all()->keyBy('code');

        $importedCount = 0;
        $failedCount = 0;
        $rowErrors = [];
        $seenArtNumbers = [];

        $rowNumber = 1; // 1 is header

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Map columns to header keys
            $data = [];
            foreach ($headers as $index => $key) {
                $data[$key] = isset($row[$index]) ? trim($row[$index]) : null;
            }

            $artNumber = $data['art_number'] ?? null;

            // Row validation
            $rules = [
                'art_number' => ['required', 'string', 'max:50'],
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'sex' => ['required', 'string', 'in:male,female,Male,Female,M,F,m,f'],
                'age' => ['required', 'integer', 'min:0', 'max:120'],
                'art_start_date' => ['required', 'string'],
                'phone' => ['nullable', 'string', 'max:25'],
                'is_pregnant' => ['nullable', 'string'],
                'is_breastfeeding' => ['nullable', 'string'],
            ];

            $validator = Validator::make($data, $rules);

            $currentErrors = [];

            if ($validator->fails()) {
                $currentErrors = $validator->errors()->all();
            }

            // Check duplicate ART in this batch
            if ($artNumber) {
                if (isset($seenArtNumbers[$artNumber])) {
                    $currentErrors[] = "Duplicate ART Number '{$artNumber}' found within this import file (previously on row {$seenArtNumbers[$artNumber]}).";
                } else {
                    $seenArtNumbers[$artNumber] = $rowNumber;
                }

                // Check duplicate ART in database
                if (Patient::where('art_number', $artNumber)->exists()) {
                    $currentErrors[] = "ART Number '{$artNumber}' already exists in the clinic database.";
                }
            }

            // Parse ART start date
            $artStartDate = null;
            if (!empty($data['art_start_date'])) {
                try {
                    $artStartDate = Carbon::parse($data['art_start_date'])->toDateString();
                } catch (\Exception $e) {
                    $currentErrors[] = "Invalid ART Start Date format '{$data['art_start_date']}'. Use YYYY-MM-DD.";
                }
            }

            if (!empty($currentErrors)) {
                $failedCount++;
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'art_number' => $artNumber,
                    'errors' => $currentErrors,
                ];
                continue;
            }

            // Normalize sex
            $sexNormalized = in_array(strtolower($data['sex']), ['male', 'm'], true) ? 'male' : 'female';

            // Normalize boolean flags
            $isPregnant = in_array(strtolower((string)($data['is_pregnant'] ?? '')), ['1', 'true', 'yes', 'y'], true);
            $isBreastfeeding = in_array(strtolower((string)($data['is_breastfeeding'] ?? '')), ['1', 'true', 'yes', 'y'], true);

            // Resolve location foreign keys
            $facility = null;
            if (!empty($data['facility_code']) && isset($facilitiesByCode[$data['facility_code']])) {
                $facility = $facilitiesByCode[$data['facility_code']];
            } else {
                $facility = $defaultFacility;
            }

            $county = $facility?->county ?? $defaultCounty;
            $state = $county?->state ?? $defaultState;

            if (!$facility || !$county || !$state) {
                $failedCount++;
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'art_number' => $artNumber,
                    'errors' => ['No valid clinic facility or county could be resolved for this patient.'],
                ];
                continue;
            }

            try {
                DB::transaction(function () use (
                    $data,
                    $artNumber,
                    $sexNormalized,
                    $artStartDate,
                    $isPregnant,
                    $isBreastfeeding,
                    $state,
                    $county,
                    $facility,
                    $userId
                ) {
                    $patient = Patient::create([
                        'art_number' => $artNumber,
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'sex' => $sexNormalized,
                        'age' => (int) $data['age'],
                        'phone' => $data['phone'] ?? null,
                        'address' => $data['address'] ?? null,
                        'art_start_date' => $artStartDate,
                        'is_pregnant' => $isPregnant,
                        'is_breastfeeding' => $isBreastfeeding,
                        'state_id' => $state->id,
                        'county_id' => $county->id,
                        'facility_id' => $facility->id,
                        'status' => 'active',
                        'created_by' => $userId,
                        'sync_status' => 'pending',
                        'last_modified_at' => now(),
                    ]);

                    // Assign initial regimen if specified or default standard 1st line TLD
                    $regimenName = !empty($data['regimen']) ? $data['regimen'] : 'TDF + 3TC + DTG (TLD)';
                    $regimenLine = !empty($data['regimen_line']) ? $data['regimen_line'] : '1st Line';

                    $patient->assignNewRegimen(
                        regimenName: $regimenName,
                        regimenLine: $regimenLine,
                        startDate: $artStartDate,
                        reason: 'Initial baseline regimen at registration',
                        userId: $userId
                    );
                });

                $importedCount++;
            } catch (\Exception $e) {
                $failedCount++;
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'art_number' => $artNumber,
                    'errors' => ['Database error: ' . $e->getMessage()],
                ];
            }
        }

        fclose($handle);

        AuditLog::log(
            action: 'bulk_imported_patients',
            tableName: 'patients',
            recordId: 'bulk',
            oldValues: null,
            newValues: ['imported' => $importedCount, 'failed' => $failedCount],
            userId: $userId
        );

        return [
            'imported' => $importedCount,
            'failed' => $failedCount,
            'errors' => $rowErrors,
        ];
    }

    /**
     * Generate sample CSV template content.
     */
    public function generateSampleCsv(): string
    {
        $facility = Facility::where('name', 'Kapoeta Civil Hospital')->first() ?? Facility::first();
        $facCode = $facility?->code ?? 'KCH-001';

        $output = "art_number,first_name,last_name,sex,age,phone,address,art_start_date,is_pregnant,is_breastfeeding,facility_code,regimen,regimen_line\n";
        $output .= "ART-10021,Amina,Hassan,female,28,+211911000101,Kapoeta South,2023-01-15,no,no,{$facCode},TDF + 3TC + DTG (TLD),1st Line\n";
        $output .= "ART-10022,Emmanuel,Lado,male,9,+211911000102,Torit Town,2024-03-10,no,no,{$facCode},ABC + 3TC + DTG,1st Line\n";
        $output .= "ART-10023,Grace,Wani,female,32,+211911000103,Magwi,2022-06-20,yes,no,{$facCode},TDF + 3TC + DTG (TLD),1st Line\n";
        $output .= "ART-10024,Joseph,Lokiru,male,45,+211911000104,Nimule,2021-11-05,no,no,{$facCode},AZT + 3TC + ATV/r,2nd Line\n";

        return $output;
    }
}
