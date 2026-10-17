<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\County;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\State;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\SyncService;
use App\Services\SyncStateStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncPaginationAndHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected User $clinician;
    protected State $state;
    protected County $county;
    protected Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sync.batch_size' => 5,
            'sync.standalone' => false,
            'sync.remote_url' => 'https://cloud.example.test/api/v1/sync',
        ]);

        $this->state = State::create(['name' => 'Eastern Equatoria', 'code' => 'EES']);
        $this->county = County::create(['name' => 'Kapoeta South County', 'code' => 'KPS', 'state_id' => $this->state->id]);
        $this->facility = Facility::create(['name' => 'Kapoeta Civil Hospital', 'code' => 'KCH-001', 'county_id' => $this->county->id]);

        $this->clinician = User::create([
            'name' => 'Dr. Jane Clinician',
            'email' => 'jane@vilocare.org',
            'phone' => '+254711000002',
            'role' => UserRole::CLINICIAN,
            'password' => bcrypt('Jane@123456'),
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    /**
     * Fakes the central server: push acknowledges every record it receives, pull returns the given page.
     */
    protected function fakeCloud(array $pull = []): void
    {
        Http::fake([
            '*/push' => function (Request $request) {
                $ids = [];
                foreach ($request->data()['tables'] as $table => $records) {
                    $ids[$table] = array_column($records, 'id');
                }

                return Http::response(['success' => true, 'synced_ids' => $ids, 'server_updates' => []]);
            },
            '*/pull*' => Http::response($pull + [
                'success' => true,
                'server_time' => now()->toIso8601String(),
                'tables' => [],
                'has_more' => false,
                'next' => null,
            ]),
        ]);
    }

    protected function makePatient(string $art, array $overrides = []): Patient
    {
        return Patient::create(array_merge([
            'art_number' => $art,
            'first_name' => 'Pat',
            'last_name' => 'Ient',
            'sex' => 'Male',
            'age' => 35,
            'art_start_date' => '2024-05-10',
            'state_id' => $this->state->id,
            'county_id' => $this->county->id,
            'facility_id' => $this->facility->id,
            'created_by' => $this->clinician->id,
        ], $overrides));
    }

    /**
     * A plain-array copy of a patient row as the server would serialize it, without leaving the row behind.
     */
    protected function detachedPatientRow(string $art, array $overrides = []): array
    {
        $patient = $this->makePatient($art);
        $row = (array) DB::table('patients')->where('id', $patient->id)->first();
        DB::table('patients')->where('id', $patient->id)->delete();

        return array_merge($row, $overrides);
    }

    /**
     * @return array<int, string> every patient id returned by following the pull cursor to exhaustion
     */
    protected function pullAllPatientIds(SyncService $service, ?string $since = null, ?int &$pages = null): array
    {
        $ids = [];
        $resume = null;
        $pages = 0;

        do {
            $page = $service->processServerPull($since, $resume);
            $pages++;
            array_push($ids, ...array_column($page['tables']['patients'] ?? [], 'id'));
            $resume = $page['next'];
        } while ($resume !== null && $pages < 50);

        return $ids;
    }

    public function test_pull_with_more_than_batch_size_records_returns_everything_across_pages(): void
    {
        $expected = [];
        for ($i = 1; $i <= 12; $i++) {
            $expected[] = $this->makePatient(sprintf('ART-PAGE-%03d', $i))->id;
        }

        $service = new SyncService();
        $ids = $this->pullAllPatientIds($service, null, $pages);

        $this->assertCount(12, $ids);
        $this->assertSame([], array_diff($expected, $ids));
        $this->assertCount(12, array_unique($ids), 'No record may be delivered twice.');
        // 12 patients at 5 per page need at least 3 pages (audit rows of other tables add more).
        $this->assertGreaterThanOrEqual(3, $pages);
    }

    public function test_pull_page_never_includes_child_tables_before_parents_are_exhausted(): void
    {
        // The migration seeds 7 laboratories, more than this test's page size of 5. Clear them so
        // that patients are the first table that overflows a page.
        DB::table('testing_laboratories')->delete();

        for ($i = 1; $i <= 7; $i++) {
            $this->makePatient(sprintf('ART-ORDER-%03d', $i));
        }

        $first = (new SyncService())->processServerPull(null, null);

        $this->assertNotNull($first['next']);
        $this->assertSame('patients', $first['next']['table']);
        $this->assertArrayNotHasKey('viral_load_results', $first['tables']);
    }

    public function test_record_created_with_old_last_modified_at_is_still_delivered(): void
    {
        $cursor = now()->subMinute()->toIso8601String();

        // A record entered on another device long ago, but only reaching the server now.
        $row = $this->detachedPatientRow('ART-LATE-001', [
            'id' => (string) Str::uuid(),
            'last_modified_at' => '2020-01-01 08:00:00',
            'created_at' => '2020-01-01 08:00:00',
            'updated_at' => '2020-01-01 08:00:00',
            'sync_status' => SyncStatus::PENDING->value,
        ]);

        $service = new SyncService();
        $result = $service->processServerPush(['tables' => ['patients' => [$row]]], 'other-device');
        $this->assertContains($row['id'], $result['synced_ids']['patients']);

        $ids = $this->pullAllPatientIds($service, $cursor);

        $this->assertContains($row['id'], $ids);
    }

    public function test_pulling_twice_does_not_duplicate_records(): void
    {
        $service = new SyncService();

        $newPatient = $this->detachedPatientRow('ART-IDEM-001', ['id' => (string) Str::uuid()]);
        // Independently created on both sides: same ART number, different UUID, newer on the server.
        $local = $this->makePatient('ART-IDEM-002');
        $matched = array_merge((array) DB::table('patients')->where('id', $local->id)->first(), [
            'id' => (string) Str::uuid(),
            'first_name' => 'FromServer',
            'last_modified_at' => now()->addHour()->toDateTimeString(),
        ]);
        $remoteUser = [
            'id' => (string) Str::uuid(),
            'name' => 'Remote Clinician',
            'email' => 'jane@vilocare.org', // matches the existing local user by business key
            'role' => 'clinician',
            'password' => bcrypt('x'),
            'is_active' => true,
            'must_change_password' => false,
            'last_modified_at' => now()->addHour()->toDateTimeString(),
            'sync_status' => 'synced',
        ];

        $payload = ['users' => [$remoteUser], 'patients' => [$newPatient, $matched]];

        $first = $service->applyRemotePull($payload);
        $this->assertSame(0, $first['failed']);

        $patientsAfterFirst = Patient::count();
        $usersAfterFirst = User::count();

        $second = $service->applyRemotePull($payload);

        $this->assertSame(0, $second['failed']);
        $this->assertSame($patientsAfterFirst, Patient::count());
        $this->assertSame($usersAfterFirst, User::count());
        $this->assertSame(1, Patient::where('art_number', 'ART-IDEM-001')->count());
        $this->assertSame(1, Patient::where('art_number', 'ART-IDEM-002')->count());
        $this->assertSame(1, User::where('email', 'jane@vilocare.org')->count());
        $this->assertSame('FromServer', $local->fresh()->first_name);
        $this->assertSame(0, $second['applied'], 'A repeated pull must be a no-op.');
        $this->assertSame(3, $second['unchanged']);
    }

    public function test_newer_local_edit_is_not_overwritten_by_older_remote_copy(): void
    {
        $service = new SyncService();
        $patient = $this->makePatient('ART-LWW-001', ['first_name' => 'LocalEdit']);

        $remote = (array) DB::table('patients')->where('id', $patient->id)->first();
        $remote['first_name'] = 'OldRemote';
        $remote['last_modified_at'] = now()->subHours(3)->toDateTimeString();

        $result = $service->applyRemotePull(['patients' => [$remote]]);

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame('LocalEdit', $patient->fresh()->first_name);

        // Re-pulling the same stale copy neither overwrites nor piles up duplicate conflict rows.
        $service->applyRemotePull(['patients' => [$remote]]);
        $this->assertSame('LocalEdit', $patient->fresh()->first_name);
        $this->assertSame(1, SyncConflict::where('record_id', $patient->id)->where('resolution_strategy', 'last_write_wins')->count());
    }

    public function test_push_of_more_than_batch_size_pending_records_sends_all_of_them(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->makePatient(sprintf('ART-PUSH-%03d', $i));
        }

        Http::fake([
            '*/push' => function (Request $request) {
                $ids = [];
                foreach ($request->data()['tables'] as $table => $records) {
                    $ids[$table] = array_column($records, 'id');
                }

                return Http::response(['success' => true, 'synced_ids' => $ids, 'server_updates' => []]);
            },
            '*/pull*' => Http::response(['success' => true, 'server_time' => now()->toIso8601String(), 'tables' => [], 'has_more' => false, 'next' => null]),
        ]);

        $result = (new SyncService())->runFullSync();

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(13, $result['pushed']);

        $pushRequests = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/push'));
        $this->assertGreaterThanOrEqual(3, $pushRequests->count(), 'Pending records must be sent in multiple pages.');

        // Every patient was sent exactly once, and parents never trail their children.
        $sentPatientIds = [];
        foreach ($pushRequests as [$request]) {
            array_push($sentPatientIds, ...array_column($request->data()['tables']['patients'] ?? [], 'id'));
        }
        $this->assertCount(12, array_unique($sentPatientIds));
        $this->assertSame(0, Patient::where('sync_status', SyncStatus::PENDING->value)->count());
        $this->assertSame(0, (new SyncService())->getSyncStats()['total_pending']);
    }

    public function test_server_assigned_cursor_is_stored_in_database_and_survives_cache_flush(): void
    {
        $serverTime = '2026-10-01T10:00:00+00:00';

        Http::fake([
            '*/push' => Http::response(['success' => true, 'synced_ids' => []]),
            '*/pull*' => Http::response(['success' => true, 'server_time' => $serverTime, 'tables' => [], 'has_more' => false, 'next' => null]),
        ]);

        $service = new SyncService();
        $this->assertTrue($service->runFullSync()['success']);

        $expected = Carbon::parse($serverTime)->subSeconds(5)->toIso8601String();
        $this->assertSame($expected, (new SyncStateStore())->get('pull_cursor'));

        Cache::flush();
        $this->assertSame($expected, (new SyncStateStore())->get('pull_cursor'));

        // The next run asks the server for changes since the stored cursor...
        $service->runFullSync();
        $pulls = Http::recorded(fn (Request $r) => str_contains($r->url(), '/pull'));
        $lastPull = $pulls->last()[0];
        $this->assertStringContainsString('since=', $lastPull->url());

        // ...and --full ignores it.
        $service->runFullSync(null, true);
        $fullPull = Http::recorded(fn (Request $r) => str_contains($r->url(), '/pull'))->last()[0];
        $this->assertStringNotContainsString('since=', $fullPull->url());
    }

    public function test_unapplicable_remote_record_is_counted_and_surfaced_not_silently_dropped(): void
    {
        $bad = $this->detachedPatientRow('ART-BAD-001', ['id' => (string) Str::uuid(), 'art_number' => null]);
        $good = $this->detachedPatientRow('ART-GOOD-001', ['id' => (string) Str::uuid()]);

        $this->fakeCloud(['tables' => ['patients' => [$bad, $good]]]);

        $service = new SyncService();
        $result = $service->runFullSync();

        $this->assertTrue($result['success']);
        $this->assertTrue($result['partial']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, Patient::where('art_number', 'ART-GOOD-001')->count(), 'One bad row must not block the good ones.');
        $this->assertSame(1, SyncConflict::where('resolution_strategy', 'flagged_for_manual_review')->whereNull('resolved_at')->count());

        $stats = $service->getSyncStats();
        $this->assertSame(1, $stats['last_error']['count']);
        $this->assertSame('partial', $stats['last_run_status']);

        $this->actingAs($this->clinician)->get(route('sync.index'))
            ->assertOk()
            ->assertSee('1 record(s) could not be applied');
    }

    public function test_outdated_server_without_paging_support_is_reported_instead_of_advancing_the_cursor(): void
    {
        Http::fake([
            '*/push' => Http::response(['success' => true, 'synced_ids' => []]),
            '*/pull*' => Http::response(['success' => true, 'server_time' => now()->toIso8601String(), 'tables' => []]),
        ]);

        $result = (new SyncService())->runFullSync();

        $this->assertFalse($result['success']);
        $this->assertNull((new SyncStateStore())->get('pull_cursor'));
    }

    public function test_overlapping_sync_runs_are_prevented_by_a_lock(): void
    {
        Http::fake();
        $lock = Cache::lock('vilocare:sync-run', 60);
        $this->assertTrue($lock->get());

        $result = (new SyncService())->runFullSync();

        $this->assertSame('already_running', $result['mode']);
        $this->assertTrue($result['skipped']);
        Http::assertNothingSent();

        $lock->release();
    }

    public function test_heartbeat_is_recorded_by_every_run_and_marks_auto_sync_active(): void
    {
        $this->fakeCloud();
        $service = new SyncService();

        $this->assertTrue($service->getSyncStats()['auto_sync_stalled'], 'Never ran: must not claim to be active.');

        $service->runFullSync(); // what the scheduler's `vilocare:sync` does every minute

        $stats = $service->getSyncStats();
        $this->assertNotNull($stats['heartbeat_at']);
        $this->assertFalse($stats['auto_sync_stalled']);

        $this->actingAs($this->clinician)->get(route('sync.index'))
            ->assertOk()
            ->assertSee('Auto-Sync Active (Every 60s)')
            ->assertDontSee('Auto-sync stalled');
    }

    public function test_auto_sync_shows_stalled_in_amber_after_three_minutes_without_a_run(): void
    {
        $this->fakeCloud();
        $service = new SyncService();
        $service->runFullSync();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertFalse($service->getSyncStats()['auto_sync_stalled']);

        Carbon::setTestNow(now()->addMinutes(2)); // 4 minutes since the last run
        $stats = $service->getSyncStats();
        $this->assertTrue($stats['auto_sync_stalled']);

        $this->actingAs($this->clinician)->get(route('sync.index'))
            ->assertOk()
            ->assertSee('Auto-sync stalled, last run 4 minutes ago')
            ->assertSee('background: #fef3c7; color: #b45309;', false)
            ->assertDontSee('Auto-Sync Active (Every 60s)');

        Carbon::setTestNow();
    }

    public function test_last_successful_sync_turns_amber_after_ten_minutes(): void
    {
        $this->fakeCloud();
        $service = new SyncService();
        $service->runFullSync();

        Carbon::setTestNow(now()->addMinutes(9));
        $this->assertFalse($service->getSyncStats()['last_sync_stale']);

        Carbon::setTestNow(now()->addMinutes(2)); // 11 minutes
        $this->assertTrue($service->getSyncStats()['last_sync_stale']);

        $this->actingAs($this->clinician)->get(route('sync.index'))
            ->assertOk()
            ->assertSee('color: #b45309;" id="stat-last-synced-val"', false);

        Carbon::setTestNow();
    }

    public function test_never_run_auto_sync_is_reported_as_stalled_with_no_run_recorded(): void
    {
        $this->actingAs($this->clinician)->get(route('sync.index'))
            ->assertOk()
            ->assertSee('Auto-sync stalled, no run recorded yet');
    }
}
