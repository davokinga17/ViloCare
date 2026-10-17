<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\TestingLaboratory;
use App\Models\User;
use App\Services\LocationReconciler;
use App\Services\SyncService;
use App\Support\LocationIds;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Testing laboratories are independent of facilities: 16 facilities, 7 laboratories.
 */
class TestingLaboratoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $this->admin = User::create([
            'name' => 'Admin Boss',
            'email' => 'admin@clinic.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_10_000001_create_testing_laboratories_and_retire_nphl_facility.php');
    }

    /** Puts the retired facility back, the way an un-migrated database would have it. */
    private function insertNphlFacility(): string
    {
        $juba = DB::table('counties')->where('code', 'JUB')->value('id');
        $id = LocationIds::facility('NPHL-017');
        DB::table('facilities')->insert([
            'id' => $id, 'county_id' => $juba, 'name' => 'National Public Health Laboratory', 'code' => 'NPHL-017',
            'facility_level' => 'National Reference Laboratory', 'sync_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_there_are_16_facilities_and_none_is_the_national_laboratory(): void
    {
        $this->assertSame(16, Facility::count());
        $this->assertFalse(Facility::where('code', 'NPHL-017')->exists());
    }

    public function test_every_facility_list_shows_16(): void
    {
        $this->assertSame(16, $this->actingAs($this->admin)->get(route('dashboard'))->viewData('facilities')->count());

        foreach (['reports.index', 'reports.due-list'] as $route) {
            $this->assertSame(16, $this->actingAs($this->admin)->get(route($route))->viewData('facilities')->count(), $route);
        }
        $this->assertSame(16, $this->actingAs($this->admin)->get(route('patients.create'))->viewData('facilities')->count());
        $this->assertSame(16, $this->actingAs($this->admin)->get(route('samples.create'))->viewData('facilities')->count());
    }

    public function test_the_seven_laboratories_are_seeded_with_deterministic_ids_and_links(): void
    {
        $labs = TestingLaboratory::with('facility')->get()->keyBy('code');

        $this->assertCount(7, $labs);
        $this->assertSame(LocationIds::laboratory('NPHL-017'), $labs['NPHL-017']->id);
        $this->assertNull($labs['NPHL-017']->facility_id, 'the National Public Health Laboratory is inside no facility');
        $this->assertSame('Kapoeta Civil Hospital', $labs['KCH-001']->facility->name);
        $this->assertSame(Facility::where('code', 'TSH-009')->value('id'), $labs['TSH-009']->facility_id);
        $this->assertSame(6, $labs->whereNotNull('facility_id')->count());
    }

    public function test_the_facility_flag_and_scope_are_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('facilities', 'has_testing_lab'));
        $this->assertFalse(method_exists(Facility::class, 'scopeTestingLabs'));
    }

    public function test_seeding_again_changes_nothing(): void
    {
        $before = TestingLaboratory::orderBy('code')->pluck('id', 'code')->all();

        $this->seed(LocationSeeder::class);

        $this->assertSame($before, TestingLaboratory::orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame(16, Facility::count());
    }

    public function test_migration_is_idempotent(): void
    {
        $migration = $this->migration();
        $labs = TestingLaboratory::orderBy('code')->pluck('id', 'code')->all();

        $migration->up();
        $migration->up();

        $this->assertSame($labs, TestingLaboratory::orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame(16, Facility::count());
        $this->assertFalse(Schema::hasColumn('facilities', 'has_testing_lab'));
    }

    public function test_migration_removes_an_unreferenced_nphl_facility_and_keeps_the_lab(): void
    {
        $this->insertNphlFacility();
        $this->assertSame(17, Facility::count());

        $this->migration()->up();

        $this->assertSame(16, Facility::count());
        $this->assertFalse(Facility::where('code', 'NPHL-017')->exists());
        $this->assertTrue(TestingLaboratory::where('code', 'NPHL-017')->where('is_active', true)->exists());
    }

    public function test_migration_refuses_to_remove_nphl_when_a_patient_references_it(): void
    {
        $facilityId = $this->insertNphlFacility();
        $county = DB::table('counties')->where('code', 'JUB')->first();
        Patient::create([
            'art_number' => 'ART-NPHL-1', 'first_name' => 'Ref', 'last_name' => 'Erenced', 'sex' => 'male', 'age' => 30,
            'art_start_date' => '2022-01-01', 'facility_id' => $facilityId, 'county_id' => $county->id,
            'state_id' => $county->state_id, 'status' => 'active',
        ]);

        try {
            $this->migration()->up();
            $this->fail('The migration must stop when the facility is referenced.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('NPHL-017', $e->getMessage());
            $this->assertStringContainsString('patients.facility_id', $e->getMessage());
            $this->assertStringContainsString('No changes were made', $e->getMessage());
        }

        $this->assertTrue(Facility::where('code', 'NPHL-017')->exists(), 'nothing was removed');
        $this->assertSame(1, Patient::where('facility_id', $facilityId)->count());
    }

    public function test_migration_refuses_when_a_sample_references_it(): void
    {
        $facilityId = $this->insertNphlFacility();
        $this->assertSame(['patients', 'sample_collections'], array_values(array_intersect(
            ['patients', 'sample_collections'],
            array_filter(Schema::getTableListing(null, false), fn ($t) => Schema::hasColumn($t, 'facility_id'))
        )));

        $patientFacility = Facility::where('code', 'KCH-001')->first();
        $patient = Patient::create([
            'art_number' => 'ART-NPHL-2', 'first_name' => 'Sam', 'last_name' => 'Ple', 'sex' => 'male', 'age' => 30,
            'art_start_date' => '2022-01-01', 'facility_id' => $patientFacility->id, 'county_id' => $patientFacility->county_id,
            'state_id' => $patientFacility->county->state_id, 'status' => 'active',
        ]);
        DB::table('sample_collections')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'patient_id' => $patient->id, 'sample_code' => 'S-NPHL-1',
            'collection_date' => '2026-01-01', 'sample_type' => 'Plasma', 'status' => 'collected',
            'facility_id' => $facilityId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sample_collections.facility_id');
        $this->migration()->up();
    }

    public function test_laboratories_sync_pull_only_after_facilities(): void
    {
        $models = array_keys(config('sync.synced_models'));
        $this->assertSame($models, array_values(array_unique($models)));
        $this->assertGreaterThan(array_search('facilities', $models), array_search('testing_laboratories', $models));
        $this->assertLessThan(array_search('users', $models), array_search('testing_laboratories', $models));
        $this->assertContains('testing_laboratories', config('sync.pull_only_tables'));
    }

    public function test_a_pulled_laboratory_is_matched_by_code_and_a_new_one_is_added(): void
    {
        $existing = TestingLaboratory::where('code', 'TSH-009')->first();
        $remote = fn (array $row) => $row + ['sync_status' => 'synced', 'last_modified_at' => now()->toDateTimeString()];

        $result = app(SyncService::class)->applyRemotePull(['testing_laboratories' => [
            // Same code, different id and a rename: the local row adopts the cloud id and the cloud name.
            $remote(['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Torit State Hospital Lab', 'code' => 'TSH-009',
                'facility_id' => $existing->facility_id, 'is_active' => true]),
            $remote(['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Juba Reference Lab', 'code' => 'JRL-018',
                'facility_id' => null, 'is_active' => true]),
        ]]);

        $this->assertSame(0, $result['failed']);
        $this->assertSame('Torit State Hospital Lab', TestingLaboratory::where('code', 'TSH-009')->value('name'));
        $this->assertSame('11111111-1111-4111-8111-111111111111', TestingLaboratory::where('code', 'TSH-009')->value('id'));
        $this->assertTrue(TestingLaboratory::where('code', 'JRL-018')->exists());
        $this->assertSame(8, TestingLaboratory::count());
        $this->assertSame(0, TestingLaboratory::where('sync_status', 'pending')->count(), 'pull-only rows are never pending upload');
    }

    public function test_a_pull_cannot_bring_the_retired_facility_back(): void
    {
        $juba = DB::table('counties')->where('code', 'JUB')->first();
        $row = [
            'id' => LocationIds::facility('NPHL-017'), 'county_id' => $juba->id, 'name' => 'National Public Health Laboratory',
            'code' => 'NPHL-017', 'facility_level' => 'National Reference Laboratory', 'sync_status' => 'synced',
            'last_modified_at' => now()->toDateTimeString(),
        ];

        // Through the sync engine (a full re-pull from a server that still has the row) ...
        app(SyncService::class)->applyRemotePull(['facilities' => [$row]]);
        // ... and through the reconciler directly.
        app(LocationReconciler::class)->apply('facilities', $row);

        $this->assertFalse(Facility::where('code', 'NPHL-017')->exists());
        $this->assertSame(16, Facility::count());
    }
}
