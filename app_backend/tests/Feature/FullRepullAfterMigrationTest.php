<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\User;
use App\Services\SyncService;
use App\Services\SyncStateStore;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A migration that adds columns to a synced table must make the next pull start from scratch: rows already
 * pulled never change on the server, so the stored cursor would skip them and the new fields would never arrive.
 */
class FullRepullAfterMigrationTest extends TestCase
{
    use RefreshDatabase;

    private SyncStateStore $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        $this->state = new SyncStateStore();
        config(['sync.standalone' => false, 'sync.remote_url' => 'https://cloud.example.test/api/v1/sync']);
    }

    /** Fakes the central server; records the `since` of each pull; `$users` is a closure(?string $since): array. */
    private function fakeCloud(\Closure $users, array &$sinceLog): void
    {
        Http::swap(new Factory()); // a new fake replaces the previous one
        Http::fake([
            '*/push' => Http::response(['success' => true, 'synced_ids' => [], 'server_updates' => []]),
            '*/pull*' => function (Request $request) use ($users, &$sinceLog) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
                $since = $q['since'] ?? null;
                $sinceLog[] = $since;

                return Http::response([
                    'success' => true,
                    'server_time' => now()->toIso8601String(),
                    'tables' => ['users' => $users($since)],
                    'next' => null,
                ]);
            },
        ]);
    }

    private function remoteUser(array $extra = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'name' => 'Clin Ician', 'email' => 'clin@repull.test', 'role' => 'clinician',
            'password' => bcrypt('x'), 'is_active' => true, 'must_change_password' => false,
            'last_modified_at' => '2026-11-01 08:00:00', 'sync_status' => 'synced',
        ], $extra);
    }

    public function test_a_flagged_pull_ignores_the_cursor_once_then_resumes_normal_pulls(): void
    {
        $since = [];
        $this->fakeCloud(fn () => [], $since);
        $this->state->put('pull_cursor', '2026-11-10T00:00:00+00:00');
        $this->state->requestFullRepull('test');

        $service = new SyncService();
        $service->runFullSync();
        $this->assertNull($since[0], 'the first pull after the flag starts from scratch');
        $this->assertFalse($this->state->needsFullRepull(), 'the flag is cleared once used');

        $service->runFullSync();
        $this->assertNotNull($since[1], 'the next pull uses the new cursor again');
    }

    public function test_a_failed_first_attempt_does_not_bring_the_old_cursor_back(): void
    {
        $this->state->put('pull_cursor', '2026-11-10T00:00:00+00:00');
        $this->state->requestFullRepull('test');
        Http::fake(['*' => Http::response('down', 500)]);

        (new SyncService())->runFullSync();

        $this->assertNull($this->state->get('pull_cursor'), 'the old cursor is gone, so the retry also pulls everything');
    }

    public function test_migrations_flag_a_repull_only_for_computers_that_have_pulled_before(): void
    {
        $this->assertFalse($this->state->needsFullRepull(), 'a fresh install needs no flag');

        $this->state->requestFullRepullIfPulledBefore('x');
        $this->assertFalse($this->state->needsFullRepull());

        $this->state->put('pull_cursor', '2026-11-10T00:00:00+00:00');
        $this->state->requestFullRepullIfPulledBefore('x');
        $this->assertTrue($this->state->needsFullRepull());
    }

    public function test_the_column_adding_migrations_request_a_repull(): void
    {
        foreach (['2026_11_03_000001_add_facility_access_to_users_table', '2026_11_04_000001_create_patient_transfers_table', '2026_11_17_000001_add_recovery_email_and_account_tokens'] as $name) {
            $source = file_get_contents(database_path("migrations/{$name}.php"));
            $this->assertStringContainsString('requestFullRepullIfPulledBefore', $source, $name);
        }
    }

    public function test_a_1_1_2_computer_gets_the_facility_fields_after_upgrading(): void
    {
        $facility = Facility::first();
        $user = $this->remoteUser();

        // 1.1.2: the server strips the new fields, so the computer stores the user without them and keeps a cursor.
        $since = [];
        $this->fakeCloud(fn () => [$user], $since);
        $service = new SyncService();
        $service->runFullSync();
        $local = User::where('email', 'clin@repull.test')->first();
        $this->assertNotNull($local);
        $this->assertNull($local->facility_id);
        $this->assertNotNull($this->state->get('pull_cursor'));

        // Upgrade to 1.1.3: the migration flags a re-pull. The server row has not changed since.
        $this->state->requestFullRepullIfPulledBefore('users: facility_id, testing_laboratory_id added');
        $this->assertTrue($this->state->needsFullRepull());

        // A server honouring `since` sends nothing for an unchanged row: without the flag the fields never arrive.
        $since = [];
        $withFacility = $user + ['facility_id' => $facility->id, 'testing_laboratory_id' => null];
        $this->fakeCloud(fn (?string $s) => $s === null ? [$withFacility] : [], $since);

        $service->runFullSync();

        $this->assertNull($since[0], 'the upgraded computer pulled from scratch');
        $this->assertSame($facility->id, User::where('email', 'clin@repull.test')->value('facility_id'));
        $this->assertSame(1, User::where('email', 'clin@repull.test')->count(), 'no duplicate user');
        $this->assertFalse($this->state->needsFullRepull());
    }

    public function test_without_the_flag_the_unchanged_user_would_be_skipped(): void
    {
        $facility = Facility::first();
        $user = $this->remoteUser();
        $since = [];
        $this->fakeCloud(fn () => [$user], $since);
        $service = new SyncService();
        $service->runFullSync();

        $withFacility = $user + ['facility_id' => $facility->id, 'testing_laboratory_id' => null];
        $this->fakeCloud(fn (?string $s) => $s === null ? [$withFacility] : [], $since);
        $service->runFullSync();

        $this->assertNull(User::where('email', 'clin@repull.test')->value('facility_id'), 'documents the bug the flag fixes');
    }

    public function test_only_administrators_can_re_pull_everything_from_the_sync_center(): void
    {
        $facilityId = Facility::first()->id;
        $make = fn (UserRole $role, string $email) => User::create([
            'name' => $email, 'email' => $email, 'role' => $role, 'facility_id' => $facilityId,
            'password' => bcrypt('Password@123'), 'must_change_password' => false, 'is_active' => true,
        ]);
        $admin = $make(UserRole::ADMINISTRATOR, 'admin@repull.test');
        $clin = $make(UserRole::CLINICIAN, 'c@repull.test');

        $this->actingAs($clin)->get('/sync')->assertOk()->assertDontSee('Re-pull everything');
        $this->actingAs($clin)->post('/sync/repull')->assertForbidden();
        $this->assertFalse($this->state->needsFullRepull());

        $this->actingAs($admin)->get('/sync')->assertOk()->assertSee('Re-pull everything');
        $this->actingAs($admin)->post('/sync/repull')->assertRedirect(route('sync.index'));
        $this->assertTrue($this->state->needsFullRepull());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'requested_full_repull')->count());
    }

    public function test_a_1_1_3_computer_gets_the_recovery_email_after_upgrading(): void
    {
        $user = $this->remoteUser();

        // 1.1.3: the server leaves the recovery columns out; the computer holds the user and a cursor.
        $since = [];
        $this->fakeCloud(fn () => [$user], $since);
        $service = new SyncService();
        $service->runFullSync();
        $this->assertNull(User::where('email', 'clin@repull.test')->value('recovery_email'));
        $this->assertNotNull($this->state->get('pull_cursor'));

        // Upgrade: the migration flags a re-pull. The server row is unchanged (same timestamp) but now carries the columns.
        $this->state->requestFullRepullIfPulledBefore('users: recovery_email, recovery_email_verified_at added');
        $withRecovery = $user + ['recovery_email' => 'real@example.org', 'recovery_email_verified_at' => '2026-11-10 09:00:00'];
        $since = [];
        $this->fakeCloud(fn (?string $s) => $s === null ? [$withRecovery] : [], $since);
        $service->runFullSync();

        $this->assertNull($since[0], 'pulled from scratch');
        $local = User::where('email', 'clin@repull.test')->first();
        $this->assertSame('real@example.org', $local->recovery_email);
        $this->assertNotNull($local->recovery_email_verified_at);
        $this->assertSame(1, User::where('email', 'clin@repull.test')->count());
    }
}