<?php

namespace Tests\Feature;

use App\Models\County;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\SampleCollection;
use App\Models\State;
use App\Models\SyncConflict;
use App\Services\LocationReconciler;
use App\Services\SyncService;
use App\Support\LocationIds;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const STAMP = '2026-10-01 08:00:00';

    // Cloud ids (differ from the random local ones created in setUp).
    private const R_STATE = 'aaaaaaaa-0000-4000-8000-000000000001';

    private const R_COUNTY_KPS = 'aaaaaaaa-0000-4000-8000-000000000002';

    private const R_COUNTY_TOR = 'aaaaaaaa-0000-4000-8000-000000000003';

    private const R_FAC_KCH = 'aaaaaaaa-0000-4000-8000-000000000004';

    private const R_FAC_TSH = 'aaaaaaaa-0000-4000-8000-000000000005';

    private const R_FAC_NKP = 'aaaaaaaa-0000-4000-8000-000000000006';

    private State $state;

    private County $kps;

    private County $tor;

    private Facility $kch;

    private Facility $tsh;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = State::create(['name' => 'Eastern Equatoria', 'code' => 'EES']);
        $this->kps = County::create(['name' => 'Kapoeta South County', 'code' => 'KPS', 'state_id' => $this->state->id]);
        $this->tor = County::create(['name' => 'Torit County', 'code' => 'TOR', 'state_id' => $this->state->id]);
        $this->kch = Facility::create(['name' => 'Kapoeta Civil Hospital', 'code' => 'KCH-001', 'county_id' => $this->kps->id]);
        $this->tsh = Facility::create(['name' => 'Torit State Hospital', 'code' => 'TSH-009', 'county_id' => $this->tor->id]);

        $this->patient = $this->makePatient('ART-LOC-1', $this->tsh, $this->tor);
    }

    private function makePatient(string $art, Facility $facility, County $county): Patient
    {
        return Patient::create([
            'art_number' => $art,
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'sex' => 'female',
            'age' => 30,
            'art_start_date' => '2024-01-01',
            'state_id' => $this->state->id,
            'county_id' => $county->id,
            'facility_id' => $facility->id,
        ]);
    }

    private function cloudLocations(bool $withNakNak = false): array
    {
        $meta = ['created_at' => self::STAMP, 'updated_at' => self::STAMP, 'last_modified_at' => self::STAMP, 'sync_status' => 'synced'];
        $facilities = [
            ['id' => self::R_FAC_KCH, 'county_id' => self::R_COUNTY_KPS, 'name' => 'Kapoeta Civil Hospital', 'code' => 'KCH-001', 'facility_level' => null, 'has_testing_lab' => true] + $meta,
            ['id' => self::R_FAC_TSH, 'county_id' => self::R_COUNTY_TOR, 'name' => 'Torit State Hospital', 'code' => 'TSH-009', 'facility_level' => null, 'has_testing_lab' => true] + $meta,
        ];
        if ($withNakNak) {
            $facilities[] = ['id' => self::R_FAC_NKP, 'county_id' => self::R_COUNTY_KPS, 'name' => 'Naknak PHCC', 'code' => 'NKP-014', 'facility_level' => null, 'has_testing_lab' => false] + $meta;
        }

        return [
            'states' => [['id' => self::R_STATE, 'name' => 'Eastern Equatoria', 'code' => 'EES'] + $meta],
            'counties' => [
                ['id' => self::R_COUNTY_KPS, 'state_id' => self::R_STATE, 'name' => 'Kapoeta South County', 'code' => 'KPS'] + $meta,
                ['id' => self::R_COUNTY_TOR, 'state_id' => self::R_STATE, 'name' => 'Torit County', 'code' => 'TOR'] + $meta,
            ],
            'facilities' => $facilities,
        ];
    }

    private function cloudSample(string $facilityId, string $code, string $patientId): array
    {
        return [
            'id' => 'bb88ddba-23a6-426d-a30e-33af0dca515b',
            'sample_code' => 'SMP-LOC-1',
            'patient_id' => $patientId,
            'collection_date' => '2026-10-01',
            'sample_type' => 'Plasma',
            'health_facility_code' => $code,
            'facility_id' => $facilityId,
            'status' => 'collected',
            'sync_status' => 'synced',
            'last_modified_at' => self::STAMP,
            'created_at' => self::STAMP,
            'updated_at' => self::STAMP,
        ];
    }

    public function test_same_code_with_different_id_is_reconciled_and_children_follow(): void
    {
        $sample = SampleCollection::create([
            'sample_code' => 'SMP-LOC-0', 'patient_id' => $this->patient->id, 'collection_date' => '2026-09-01',
            'health_facility_code' => 'TSH-009', 'facility_id' => $this->tsh->id,
        ]);

        $result = app(SyncService::class)->applyRemotePull($this->cloudLocations());

        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, State::where('code', 'EES')->count());
        $this->assertSame(self::R_STATE, State::where('code', 'EES')->value('id'));
        $this->assertSame(self::R_COUNTY_KPS, County::where('code', 'KPS')->value('id'));
        $this->assertSame(self::R_STATE, County::where('code', 'TOR')->value('state_id'));
        $this->assertSame(self::R_FAC_TSH, Facility::where('code', 'TSH-009')->value('id'));
        $this->assertSame(self::R_COUNTY_TOR, Facility::where('code', 'TSH-009')->value('county_id'));

        $patient = $this->patient->fresh();
        $this->assertSame(self::R_FAC_TSH, $patient->facility_id);
        $this->assertSame(self::R_COUNTY_TOR, $patient->county_id);
        $this->assertSame(self::R_STATE, $patient->state_id);
        $this->assertSame(self::R_FAC_TSH, $sample->fresh()->facility_id);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        // Adopting cloud ids is not a local edit: sync status and clock of the children are untouched.
        $this->assertSame($this->patient->sync_status, $patient->sync_status);
        $this->assertEquals($this->patient->last_modified_at, $patient->last_modified_at);
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $service = app(SyncService::class);
        $service->applyRemotePull($this->cloudLocations());

        $snapshot = [
            DB::table('states')->orderBy('id')->get()->toJson(),
            DB::table('counties')->orderBy('id')->get()->toJson(),
            DB::table('facilities')->orderBy('id')->get()->toJson(),
            DB::table('patients')->orderBy('id')->get()->toJson(),
        ];

        $second = $service->applyRemotePull($this->cloudLocations());

        $this->assertSame(0, $second['applied']);
        $this->assertSame(0, $second['failed']);
        $this->assertSame(5, $second['unchanged']);
        $this->assertSame($snapshot, [
            DB::table('states')->orderBy('id')->get()->toJson(),
            DB::table('counties')->orderBy('id')->get()->toJson(),
            DB::table('facilities')->orderBy('id')->get()->toJson(),
            DB::table('patients')->orderBy('id')->get()->toJson(),
        ]);
    }

    public function test_sample_for_remote_only_facility_applies_after_locations_are_pulled(): void
    {
        $service = app(SyncService::class);
        $service->applyRemotePull($this->cloudLocations());
        $sample = $this->cloudSample(self::R_FAC_NKP, 'NKP-014', $this->patient->id);

        // Facility unknown locally: the sample is flagged, not attached to a guessed facility.
        $first = $service->applyRemotePull(['sample_collections' => [$sample]]);
        $this->assertSame(1, $first['failed']);
        $this->assertDatabaseMissing('sample_collections', ['sample_code' => 'SMP-LOC-1']);
        $conflict = SyncConflict::where('table_name', 'sample_collections')->whereNull('resolved_at')->first();
        $this->assertNotNull($conflict);
        $this->assertStringContainsString('NOT moved', $conflict->resolution_notes);

        // Locations arrive (Naknak is remote-only), the retry applies the sample and closes the conflict.
        $service->applyRemotePull($this->cloudLocations(withNakNak: true));
        $retry = $service->retryFlaggedConflicts();

        $this->assertSame(['retried' => 1, 'resolved' => 1, 'still_failing' => 0], $retry);
        $this->assertSame(self::R_FAC_NKP, SampleCollection::where('sample_code', 'SMP-LOC-1')->value('facility_id'));
        $resolved = $conflict->fresh();
        $this->assertNotNull($resolved->resolved_at);
        $this->assertStringContainsString('Auto-resolved', $resolved->resolution_notes);
    }

    public function test_sample_resolves_by_facility_code_when_its_id_is_unknown(): void
    {
        $service = app(SyncService::class);
        $service->applyRemotePull($this->cloudLocations());
        $sample = $this->cloudSample('cccccccc-0000-4000-8000-00000000dead', 'TSH-009', $this->patient->id);

        $result = $service->applyRemotePull(['sample_collections' => [$sample]]);

        $this->assertSame(0, $result['failed']);
        $this->assertSame(self::R_FAC_TSH, SampleCollection::where('sample_code', 'SMP-LOC-1')->value('facility_id'));
    }

    public function test_patient_with_unknown_facility_is_flagged_not_silently_moved(): void
    {
        $service = app(SyncService::class);
        $before = $this->patient->fresh()->only(['state_id', 'county_id', 'facility_id']);

        $remote = $this->patient->fresh()->getAttributes();
        $remote['facility_id'] = 'dddddddd-0000-4000-8000-000000000009';
        $remote['last_modified_at'] = now()->addDay()->toDateTimeString();
        $remote['first_name'] = 'Changed';

        $result = $service->applyRemotePull(['patients' => [$remote]]);

        $this->assertSame(1, $result['failed']);
        $this->assertSame($before, $this->patient->fresh()->only(['state_id', 'county_id', 'facility_id']));
        $this->assertSame('Test', $this->patient->fresh()->first_name);
        $this->assertDatabaseMissing('facilities', ['name' => 'Kapoeta Civil Hospital', 'id' => $remote['facility_id']]);
        $this->assertTrue(SyncConflict::where('table_name', 'patients')->whereNull('resolved_at')
            ->where('resolution_strategy', 'flagged_for_manual_review')->exists());

        // A brand-new patient with an unknown facility is not created either.
        $new = $remote;
        $new['id'] = 'eeeeeeee-0000-4000-8000-000000000001';
        $new['art_number'] = 'ART-NEW-1';
        $this->assertSame(1, $service->applyRemotePull(['patients' => [$new]])['failed']);
        $this->assertDatabaseMissing('patients', ['art_number' => 'ART-NEW-1']);
    }

    public function test_patient_silently_moved_earlier_is_restored_from_the_cloud_copy(): void
    {
        $service = app(SyncService::class);
        $service->applyRemotePull($this->cloudLocations());

        // The old fallback had moved this patient to Kapoeta locally; the cloud says Torit.
        $cloud = $this->patient->fresh()->getAttributes();
        DB::table('patients')->where('id', $this->patient->id)->update([
            'facility_id' => self::R_FAC_KCH, 'county_id' => self::R_COUNTY_KPS,
        ]);
        $cloud['facility_id'] = self::R_FAC_TSH;
        $cloud['county_id'] = self::R_COUNTY_TOR;

        $result = $service->applyRemotePull(['patients' => [$cloud]]);

        $this->assertSame(1, $result['applied']);
        $this->assertSame(self::R_FAC_TSH, $this->patient->fresh()->facility_id);
        $this->assertSame(self::R_COUNTY_TOR, $this->patient->fresh()->county_id);
    }

    public function test_plan_and_restore_report_patients_whose_location_differs_from_the_cloud(): void
    {
        $reconciler = app(LocationReconciler::class);
        $remote = $this->cloudLocations();
        $moved = $this->makePatient('ART-LOC-2', $this->kch, $this->kps);
        DB::table('patients')->where('id', $moved->id)->update(['facility_id' => $this->kch->id]);

        $remotePatients = [
            $this->patient->id => ['state_id' => self::R_STATE, 'county_id' => self::R_COUNTY_TOR, 'facility_id' => self::R_FAC_TSH],
            // Same patient id, but the cloud has them at Torit; locally they were moved to Kapoeta.
            $moved->id => ['state_id' => self::R_STATE, 'county_id' => self::R_COUNTY_TOR, 'facility_id' => self::R_FAC_TSH],
        ];

        $plan = $reconciler->patientRestorePlan($remote, $remotePatients);
        $this->assertCount(1, $plan);
        $this->assertSame($moved->id, $plan[0]['id']);
        $this->assertSame('KCH-001', $plan[0]['from']['facility_id']);
        $this->assertSame('TSH-009', $plan[0]['to']['facility_id']);

        app(SyncService::class)->applyRemotePull($remote);
        $this->assertSame(['restored' => 1, 'unresolved' => 0], $reconciler->restorePatientLocations($plan));
        $this->assertSame(self::R_FAC_TSH, $moved->fresh()->facility_id);
        $this->assertSame([], $reconciler->patientRestorePlan($remote, $remotePatients));
    }

    public function test_deterministic_location_ids_are_stable_and_keep_canonical_ids(): void
    {
        $this->assertSame(LocationIds::facility('TSH-009'), LocationIds::facility('TSH-009'));
        $this->assertNotSame(LocationIds::facility('TSH-009'), LocationIds::facility('NKP-014'));
        // Pinned value: guards the namespace constant (changing it would change every derived id).
        $this->assertSame('51a853e0-683c-568c-94f8-3f320ebd9289', LocationIds::facility('TSH-009'));
        $this->assertSame('3666f0f5-c0b9-45d6-9924-368a2bf8fece', LocationIds::state('EES'));
        $this->assertSame('6927016f-4233-46ba-b21a-c4c4f2df72c9', LocationIds::county('EES', 'KPS'));
        $this->assertSame('25ca7235-b901-4215-b2ea-733c059c7c7f', LocationIds::facility('KCH-001'));

        // Two independent databases seeding the same facility end up with the same id.
        $wipe = function () {
            DB::table('sample_collections')->delete();
            DB::table('patients')->delete();
            DB::table('facilities')->delete();
            DB::table('counties')->delete();
            DB::table('states')->delete();
        };
        $wipe();
        $this->seed(LocationSeeder::class);
        $first = DB::table('facilities')->pluck('id', 'code')->all();
        $wipe();
        $this->seed(LocationSeeder::class);

        $this->assertSame($first, DB::table('facilities')->pluck('id', 'code')->all());
        $this->assertSame(LocationIds::facility('TSH-009'), $first['TSH-009']);
        $this->assertArrayNotHasKey('NPHL-017', $first, 'the National Public Health Laboratory is a testing laboratory, not a facility');
        $this->assertCount(16, $first);
    }

    public function test_seeder_rerun_changes_nothing_and_deletes_nothing_referenced(): void
    {
        $this->seed(LocationSeeder::class);
        $ids = DB::table('facilities')->pluck('id', 'code')->all();
        $counts = [DB::table('states')->count(), DB::table('counties')->count(), DB::table('facilities')->count()];

        $this->seed(LocationSeeder::class);

        $this->assertSame($ids, DB::table('facilities')->pluck('id', 'code')->all());
        $this->assertSame($counts, [DB::table('states')->count(), DB::table('counties')->count(), DB::table('facilities')->count()]);
        // Pre-existing rows kept their ids and the patient was not touched.
        $this->assertSame($this->tsh->id, $ids['TSH-009']);
        $this->assertSame($this->tsh->id, $this->patient->fresh()->facility_id);
    }

    public function test_locations_are_pull_only_never_pushed_and_never_accepted_from_clients(): void
    {
        $service = app(SyncService::class);

        $payload = $service->getPendingLocalPayload();
        $this->assertSame([], $payload['tables']['states']);
        $this->assertSame([], $payload['tables']['facilities']);
        $this->assertGreaterThan(0, State::where('sync_status', 'pending')->count());

        $rogue = ['id' => 'ffffffff-0000-4000-8000-000000000001', 'county_id' => $this->kps->id, 'name' => 'Rogue Clinic',
            'code' => 'ROG-001', 'last_modified_at' => now()->toDateTimeString()];
        $this->postJson('/api/v1/sync/push', [
            'device_id' => 'clinic', 'timestamp' => now()->toIso8601String(), 'tables' => ['facilities' => [$rogue]],
        ], ['X-Sync-Token' => config('sync.api_token')])->assertOk();

        $this->assertDatabaseMissing('facilities', ['code' => 'ROG-001']);
    }

    public function test_locations_endpoint_requires_the_token_and_returns_only_reference_data(): void
    {
        $this->getJson('/api/v1/sync/locations')->assertStatus(401);

        $response = $this->getJson('/api/v1/sync/locations', ['X-Sync-Token' => config('sync.api_token')])->assertOk();
        $this->assertContains('TSH-009', array_column($response->json('tables.facilities'), 'code'));
        $this->assertContains('EES', array_column($response->json('tables.states'), 'code'));
        $this->assertArrayNotHasKey('patients', $response->json('tables'));
    }

    public function test_reconcile_command_dry_run_writes_nothing_and_real_run_reconciles(): void
    {
        $locations = $this->cloudLocations(withNakNak: true);
        Http::fake([
            '*/sync/locations' => Http::response(['success' => true, 'tables' => $locations]),
            '*/sync/pull*' => Http::response(['tables' => ['patients' => [[
                'id' => $this->patient->id, 'state_id' => self::R_STATE, 'county_id' => self::R_COUNTY_TOR, 'facility_id' => self::R_FAC_TSH,
            ]]], 'next' => null]),
        ]);
        $unresolved = SyncConflict::create([
            'table_name' => 'sample_collections', 'record_id' => 'bb88ddba-23a6-426d-a30e-33af0dca515b',
            'local_payload' => [], 'remote_payload' => $this->cloudSample(self::R_FAC_NKP, 'NKP-014', $this->patient->id),
            'resolution_strategy' => 'flagged_for_manual_review', 'resolution_notes' => 'FOREIGN KEY constraint failed',
        ]);

        $before = DB::table('facilities')->orderBy('id')->get()->toJson();
        $this->artisan('vilocare:reconcile-locations', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame($before, DB::table('facilities')->orderBy('id')->get()->toJson());
        $this->assertNull($unresolved->fresh()->resolved_at);

        // Declining the prompt also writes nothing.
        $this->artisan('vilocare:reconcile-locations')->expectsConfirmation('Write these changes to the local database? A backup is taken first.', 'no')->assertExitCode(1);
        $this->assertSame($before, DB::table('facilities')->orderBy('id')->get()->toJson());

        $this->artisan('vilocare:reconcile-locations')
            ->expectsConfirmation('Write these changes to the local database? A backup is taken first.', 'yes')
            ->assertExitCode(0);

        $this->assertSame(self::R_FAC_TSH, $this->patient->fresh()->facility_id);
        $this->assertDatabaseHas('facilities', ['id' => self::R_FAC_NKP, 'code' => 'NKP-014']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertNotNull($unresolved->fresh()->resolved_at);
        $this->assertSame(1, SampleCollection::where('sample_code', 'SMP-LOC-1')->count());

        // Second run: nothing left to do.
        $this->artisan('vilocare:reconcile-locations')->expectsOutputToContain('Already reconciled')->assertExitCode(0);
    }

    public function test_locations_compare_command_reports_id_mismatches_read_only(): void
    {
        Http::fake([
            '*/sync/locations' => Http::response(['success' => true, 'tables' => $this->cloudLocations(withNakNak: true)]),
            '*/sync/pull*' => Http::response(['tables' => ['patients' => [], 'sample_collections' => []], 'next' => null]),
        ]);
        $before = DB::table('facilities')->orderBy('id')->get()->toJson();

        $this->artisan('vilocare:locations-compare')->expectsOutputToContain('id_differs=')->assertExitCode(0);

        $this->assertSame($before, DB::table('facilities')->orderBy('id')->get()->toJson());
    }
}
