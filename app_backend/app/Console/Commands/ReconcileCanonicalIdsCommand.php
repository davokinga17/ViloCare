<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconcileCanonicalIdsCommand extends Command
{
    protected $signature = 'vilocare:reconcile-ids';
    protected $description = 'Reconcile local SQLite seed IDs (users, locations, baseline patients) with Render canonical cloud IDs and update all foreign key references';

    public function handle(): int
    {
        $this->info('Starting ViLoCare canonical ID reconciliation...');

        $canonicalUsers = [
            'admin@vilocare.org' => '16b2d3c2-8d5a-45cd-8465-0862aced7a4a',
            'clinician@vilocare.org' => 'd4bf772f-e350-4ac8-a8ec-d4f7839051fb',
            'labtech@vilocare.org' => 'a07534c6-ec2b-43ae-9f07-3bc4b2941dc4',
            'dataclerk@vilocare.org' => '6bcaf037-e984-4160-be4b-328dc53f42c0',
            'newstaff@vilocare.org' => '6eba327b-0338-4c8f-9c31-f1b284f0b159',
        ];

        $canonicalLocations = [
            'states' => [
                ['name' => 'Eastern Equatoria', 'code' => 'EES', 'id' => '3666f0f5-c0b9-45d6-9924-368a2bf8fece'],
            ],
            'counties' => [
                ['name' => 'Kapoeta South County', 'code' => 'KPS', 'id' => '6927016f-4233-46ba-b21a-c4c4f2df72c9', 'state_id' => '3666f0f5-c0b9-45d6-9924-368a2bf8fece'],
            ],
            'facilities' => [
                ['name' => 'Kapoeta Civil Hospital', 'code' => 'KCH-001', 'id' => '25ca7235-b901-4215-b2ea-733c059c7c7f', 'county_id' => '6927016f-4233-46ba-b21a-c4c4f2df72c9', 'state_id' => '3666f0f5-c0b9-45d6-9924-368a2bf8fece'],
            ],
        ];

        $canonicalPatients = [
            'ART-10001' => 'a752162d-8c63-4423-b89d-284d29ba3313',
            'ART-10002' => '2a400558-e086-4aa6-ba80-15496907ef71',
            'ART-10003' => 'ac2aaf5f-7cb5-497e-aea5-0e028d73a332',
            'ART-10004' => 'dceac4e9-d42a-41ce-9ee8-e50083e189c5',
            'ART-10005' => 'd1442e30-cb47-4a52-a6bc-e67443fa93e3',
        ];

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($isSqlite) {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }

        DB::beginTransaction();
        try {
            // 1. Reconcile Users
            $this->line('1. Reconciling staff accounts...');
            foreach ($canonicalUsers as $email => $targetId) {
                $user = DB::table('users')->where('email', $email)->first();
                if ($user && $user->id !== $targetId) {
                    $oldId = $user->id;
                    $this->line("   - Re-keying user {$email}: {$oldId} -> {$targetId}");

                    DB::table('users')->where('id', $oldId)->update([
                        'id' => $targetId,
                        'sync_status' => 'synced',
                    ]);

                    // Update referencing tables
                    DB::table('patients')->where('created_by', $oldId)->update(['created_by' => $targetId]);
                    if (Schema::hasTable('regimen_histories')) {
                        DB::table('regimen_histories')->where('recorded_by', $oldId)->update(['recorded_by' => $targetId]);
                    }
                    if (Schema::hasTable('viral_load_results')) {
                        DB::table('viral_load_results')->where('requesting_clinician_id', $oldId)->update(['requesting_clinician_id' => $targetId]);
                        DB::table('viral_load_results')->where('recorded_by', $oldId)->update(['recorded_by' => $targetId]);
                    }
                    if (Schema::hasTable('eac_sessions')) {
                        DB::table('eac_sessions')->where('counselor_id', $oldId)->update(['counselor_id' => $targetId]);
                    }
                    if (Schema::hasTable('sample_collections')) {
                        DB::table('sample_collections')->where('collector_id', $oldId)->update(['collector_id' => $targetId]);
                        DB::table('sample_collections')->where('received_by', $oldId)->update(['received_by' => $targetId]);
                    }
                    if (Schema::hasTable('sample_rejections')) {
                        DB::table('sample_rejections')->where('rejected_by', $oldId)->update(['rejected_by' => $targetId]);
                    }
                    if (Schema::hasTable('appointments')) {
                        DB::table('appointments')->where('scheduled_by', $oldId)->update(['scheduled_by' => $targetId]);
                        DB::table('appointments')->where('attended_by', $oldId)->update(['attended_by' => $targetId]);
                    }
                    if (Schema::hasTable('audit_logs')) {
                        DB::table('audit_logs')->where('user_id', $oldId)->update(['user_id' => $targetId]);
                    }
                } elseif ($user) {
                    DB::table('users')->where('id', $targetId)->update(['sync_status' => 'synced']);
                }
            }

            // 2. Reconcile Locations
            $this->line('2. Reconciling lookup locations...');
            // State
            foreach ($canonicalLocations['states'] as $st) {
                $existing = DB::table('states')->where('name', $st['name'])->first();
                if ($existing && $existing->id !== $st['id']) {
                    $oldId = $existing->id;
                    $this->line("   - Re-keying state {$st['name']}: {$oldId} -> {$st['id']}");
                    DB::table('states')->where('id', $oldId)->update(['id' => $st['id']]);
                    DB::table('counties')->where('state_id', $oldId)->update(['state_id' => $st['id']]);
                    if (Schema::hasColumn('facilities', 'state_id')) {
                        DB::table('facilities')->where('state_id', $oldId)->update(['state_id' => $st['id']]);
                    }
                    DB::table('patients')->where('state_id', $oldId)->update(['state_id' => $st['id']]);
                }
            }

            // County
            foreach ($canonicalLocations['counties'] as $ct) {
                $existing = DB::table('counties')->where('name', $ct['name'])->first();
                if ($existing && $existing->id !== $ct['id']) {
                    $oldId = $existing->id;
                    $this->line("   - Re-keying county {$ct['name']}: {$oldId} -> {$ct['id']}");
                    DB::table('counties')->where('id', $oldId)->update(['id' => $ct['id'], 'state_id' => $ct['state_id']]);
                    DB::table('facilities')->where('county_id', $oldId)->update(['county_id' => $ct['id']]);
                    DB::table('patients')->where('county_id', $oldId)->update(['county_id' => $ct['id']]);
                }
            }

            // Facility
            foreach ($canonicalLocations['facilities'] as $fac) {
                $existing = DB::table('facilities')->where('name', $fac['name'])->first();
                if ($existing && $existing->id !== $fac['id']) {
                    $oldId = $existing->id;
                    $this->line("   - Re-keying facility {$fac['name']}: {$oldId} -> {$fac['id']}");
                    $facUpdate = ['id' => $fac['id'], 'county_id' => $fac['county_id']];
                    if (Schema::hasColumn('facilities', 'state_id')) {
                        $facUpdate['state_id'] = $fac['state_id'];
                    }
                    DB::table('facilities')->where('id', $oldId)->update($facUpdate);
                    DB::table('patients')->where('facility_id', $oldId)->update([
                        'facility_id' => $fac['id'],
                        'county_id' => $fac['county_id'],
                        'state_id' => $fac['state_id'],
                    ]);
                }
            }

            // Also ensure ALL patients reference Kapoeta Civil Hospital and valid Eastern Equatoria IDs
            $kapoetaFacId = '25ca7235-b901-4215-b2ea-733c059c7c7f';
            $kapoetaCtyId = '6927016f-4233-46ba-b21a-c4c4f2df72c9';
            $eeStateId = '3666f0f5-c0b9-45d6-9924-368a2bf8fece';

            DB::table('patients')->update([
                'facility_id' => $kapoetaFacId,
                'county_id' => $kapoetaCtyId,
                'state_id' => $eeStateId,
            ]);

            // 3. Reconcile Baseline Patients
            $this->line('3. Reconciling baseline patients (ART-10001 through ART-10005)...');
            foreach ($canonicalPatients as $artNum => $targetId) {
                $patient = DB::table('patients')->where('art_number', $artNum)->first();
                if ($patient && $patient->id !== $targetId) {
                    $oldId = $patient->id;
                    $this->line("   - Re-keying patient {$artNum}: {$oldId} -> {$targetId}");

                    DB::table('patients')->where('id', $oldId)->update([
                        'id' => $targetId,
                        'sync_status' => 'synced',
                    ]);

                    if (Schema::hasTable('regimen_histories')) {
                        DB::table('regimen_histories')->where('patient_id', $oldId)->update(['patient_id' => $targetId]);
                    }
                    if (Schema::hasTable('viral_load_results')) {
                        DB::table('viral_load_results')->where('patient_id', $oldId)->update(['patient_id' => $targetId]);
                    }
                    if (Schema::hasTable('eac_episodes')) {
                        DB::table('eac_episodes')->where('patient_id', $oldId)->update(['patient_id' => $targetId]);
                    }
                    if (Schema::hasTable('appointments')) {
                        DB::table('appointments')->where('patient_id', $oldId)->update(['patient_id' => $targetId]);
                    }
                    if (Schema::hasTable('notification_logs')) {
                        DB::table('notification_logs')->where('patient_id', $oldId)->update(['patient_id' => $targetId]);
                    }
                    if (Schema::hasTable('sample_collections')) {
                        DB::table('sample_collections')->where('patient_id', $oldId)->update(['patient_id' => $targetId]);
                    }
                } elseif ($patient) {
                    DB::table('patients')->where('id', $targetId)->update(['sync_status' => 'synced']);
                }
            }

            DB::commit();

            if ($isSqlite) {
                DB::statement('PRAGMA foreign_keys = ON;');
            }

            $this->info('Canonical ID reconciliation completed successfully!');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($isSqlite) {
                DB::statement('PRAGMA foreign_keys = ON;');
            }
            $this->error('Reconciliation failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
