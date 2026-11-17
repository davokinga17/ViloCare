<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\User;
use App\Services\SyncService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Apps 1.1.2 and earlier have no users.facility_id / users.testing_laboratory_id columns (and no patient_transfers
 * table). The central server must not send them what they cannot store, and a new app must ignore unknown columns.
 */
class SyncClientVersionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'compat-token-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        config(['sync.api_token' => self::TOKEN]);

        User::create([
            'name' => 'Clin', 'email' => 'clin@compat.test', 'role' => UserRole::CLINICIAN,
            'facility_id' => Facility::first()->id,
            'password' => Hash::make('Password@123'), 'must_change_password' => false, 'is_active' => true,
        ]);
    }

    private function pull(?string $version): array
    {
        $headers = ['X-Sync-Token' => self::TOKEN];
        if ($version !== null) {
            $headers['X-Client-Version'] = $version;
        }

        return $this->getJson('/api/v1/sync/pull', $headers)->assertOk()->json();
    }

    private function userRow(array $pull, string $email = 'clin@compat.test'): array
    {
        return collect($pull['tables']['users'])->firstWhere('email', $email);
    }

    public function test_the_server_sends_the_new_user_fields_only_to_1_1_3_and_later(): void
    {
        foreach (['1.1.3', '1.1.4', '1.2.0', '2.0.0', 'v1.1.3', '1.1.3-beta.1'] as $version) {
            $row = $this->userRow($this->pull($version));
            $this->assertArrayHasKey('facility_id', $row, "{$version} gets the new fields");
            $this->assertArrayHasKey('testing_laboratory_id', $row, "{$version} gets the new fields");
            $this->assertNotNull($row['facility_id']);
        }

        foreach (['1.1.2', '1.1.0', '1.0.9', '1.1.2-beta.1', '0.9', 'garbage'] as $version) {
            $row = $this->userRow($this->pull($version));
            $this->assertArrayNotHasKey('facility_id', $row, "{$version} must not get the new fields");
            $this->assertArrayNotHasKey('testing_laboratory_id', $row, "{$version} must not get the new fields");
            $this->assertSame('clin@compat.test', $row['email'], 'the rest of the user row is unchanged');
            $this->assertArrayHasKey('role', $row);
        }
    }

    public function test_a_request_without_a_version_header_counts_as_an_old_app(): void
    {
        $row = $this->userRow($this->pull(null));
        $this->assertArrayNotHasKey('facility_id', $row);
        $this->assertArrayNotHasKey('testing_laboratory_id', $row);
    }

    public function test_other_tables_and_columns_are_not_touched_for_old_apps(): void
    {
        $old = $this->pull('1.1.2');
        $new = $this->pull('1.1.3');

        $this->assertArrayHasKey('patient_transfers', $new['tables']);
        $this->assertArrayNotHasKey('patient_transfers', $old['tables'], 'a 1.1.2 app has no such table');
        $this->assertSame(
            array_keys(array_diff_key($new['tables'], ['patient_transfers' => 1])),
            array_keys($old['tables']),
            'no other table is lost'
        );
        $this->assertSame(array_keys($new['tables']['facilities'][0]), array_keys($old['tables']['facilities'][0]));
    }

    public function test_the_full_sync_endpoint_also_strips_for_old_apps(): void
    {
        $body = ['device_id' => 'old-computer', 'timestamp' => now()->toIso8601String(), 'tables' => ['users' => []]];
        $old = $this->postJson('/api/v1/sync/full', $body, ['X-Sync-Token' => self::TOKEN, 'X-Client-Version' => '1.1.2'])->assertOk()->json();
        $new = $this->postJson('/api/v1/sync/full', $body, ['X-Sync-Token' => self::TOKEN, 'X-Client-Version' => '1.1.3'])->assertOk()->json();

        $this->assertArrayNotHasKey('facility_id', $this->userRow($old['pull']));
        $this->assertArrayHasKey('facility_id', $this->userRow($new['pull']));
    }

    /** Turns this database into an old app's: users without the two assignment columns. */
    private function becomeVersion112Database(): void
    {
        Schema::table('users', function ($table) {
            $table->dropConstrainedForeignId('facility_id');
            $table->dropConstrainedForeignId('testing_laboratory_id');
        });
        $this->assertFalse(Schema::hasColumn('users', 'facility_id'));
    }

    public function test_a_simulated_1_1_2_client_pulls_users_and_applies_them_without_errors(): void
    {
        $payload = $this->pull('1.1.2');
        $this->assertCount(1, $payload['tables']['users']);

        $this->becomeVersion112Database();
        User::query()->delete();

        $result = (new SyncService)->applyRemotePull($payload['tables']);

        $this->assertSame(0, $result['failed'], 'the old app must not report failed records');
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, User::where('email', 'clin@compat.test')->count());
        $this->assertSame(0, \App\Models\SyncConflict::count(), 'nothing is flagged for manual review');
    }

    public function test_a_new_app_ignores_unknown_columns_instead_of_failing(): void
    {
        // The row a NEWER server might send: this database has no such column. (Also what a 1.1.3 app sees if a
        // later release adds a field it does not know.)
        $payload = $this->pull('1.1.3');
        $payload['tables']['users'][0]['column_from_the_future'] = 'x';
        User::query()->delete();

        $result = (new SyncService)->applyRemotePull($payload['tables']);

        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, User::where('email', 'clin@compat.test')->count());
        $this->assertSame(Facility::first()->id, User::where('email', 'clin@compat.test')->value('facility_id'));
    }

    public function test_a_new_app_that_receives_the_new_fields_on_an_old_database_still_applies_the_row(): void
    {
        // Even if an old server (or a mis-versioned request) sends the new columns to a database without them.
        $payload = $this->pull('1.1.3');
        $this->becomeVersion112Database();
        User::query()->delete();

        $result = (new SyncService)->applyRemotePull($payload['tables']);

        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, User::where('email', 'clin@compat.test')->count());
    }

    public function test_an_old_app_pushing_a_user_does_not_erase_the_assignment_on_the_server(): void
    {
        $user = User::where('email', 'clin@compat.test')->firstOrFail();
        $facilityId = $user->facility_id;

        // A 1.1.2 app edits the user (phone) and pushes the row WITHOUT the new fields.
        $row = collect($this->pull('1.1.2')['tables']['users'])->firstWhere('email', 'clin@compat.test');
        $row['phone'] = '+211999000111';
        $row['last_modified_at'] = now()->addMinute()->toDateTimeString();

        $this->postJson('/api/v1/sync/push', [
            'device_id' => 'old-computer', 'timestamp' => now()->toIso8601String(), 'tables' => ['users' => [$row]],
        ], ['X-Sync-Token' => self::TOKEN, 'X-Client-Version' => '1.1.2'])->assertOk();

        $user->refresh();
        $this->assertSame('+211999000111', $user->phone);
        $this->assertSame($facilityId, $user->facility_id, 'the facility assignment survives an old app pushing the user');
    }

    public function test_versioned_tables_are_left_out_for_old_apps(): void
    {
        // patient_transfers (a later release) is listed in config; the service skips such a table for old apps.
        config(['sync.versioned_features.tables' => ['appointments']]);
        $service = new SyncService;

        $this->assertArrayNotHasKey('appointments', $service->processServerPull(null, null, '1.1.2')['tables']);
        $this->assertArrayHasKey('appointments', $service->processServerPull(null, null, '1.1.3')['tables']);
        $this->assertArrayHasKey('appointments', $service->processServerPull(null, null, null)['tables'], 'internal calls get everything');
    }

    public function test_version_support_check(): void
    {
        $service = new SyncService;
        $this->assertFalse($service->supportsVersionedFeatures(''));
        $this->assertFalse($service->supportsVersionedFeatures('1.1.2'));
        $this->assertTrue($service->supportsVersionedFeatures('1.1.3'));
        $this->assertTrue($service->supportsVersionedFeatures('1.10.0'), 'compared as numbers, not text');
        $this->assertTrue($service->supportsVersionedFeatures('1.1.3-beta.2'));
    }

    public function test_recovery_email_columns_reach_only_apps_from_1_1_4(): void
    {
        User::where('email', 'clin@compat.test')->first()
            ->forceFill(['recovery_email' => 'real@example.org', 'recovery_email_verified_at' => now()])->save();

        foreach (['1.1.4', 'v1.1.4', '1.2.0', '2.0.0'] as $version) {
            $row = $this->userRow($this->pull($version));
            $this->assertSame('real@example.org', $row['recovery_email'], "{$version} gets the recovery email");
            $this->assertArrayHasKey('recovery_email_verified_at', $row);
            $this->assertArrayHasKey('facility_id', $row);
        }

        // 1.1.3 knows the facility fields but not the recovery email; 1.1.2 and older know neither.
        $row = $this->userRow($this->pull('1.1.3'));
        $this->assertArrayHasKey('facility_id', $row, '1.1.3 still gets the facility fields');
        $this->assertArrayNotHasKey('recovery_email', $row);
        $this->assertArrayNotHasKey('recovery_email_verified_at', $row);

        foreach (['1.1.2', '1.1.0', '1.0.9', '', 'garbage'] as $version) {
            $row = $this->userRow($this->pull($version === '' ? null : $version));
            $this->assertArrayNotHasKey('recovery_email', $row, "{$version} must not get the recovery email");
            $this->assertArrayNotHasKey('recovery_email_verified_at', $row);
            $this->assertArrayNotHasKey('facility_id', $row);
            $this->assertSame('clin@compat.test', $row['email']);
        }
    }

    public function test_the_account_token_table_is_never_synced(): void
    {
        $this->assertNotContains('account_tokens', array_keys($this->pull('1.1.4')['tables']));
        $this->assertNotContains('account_tokens', array_keys($this->pull('1.1.3')['tables']));
        $this->assertNotContains('account_tokens', array_keys($this->pull(null)['tables']));
    }

    public function test_the_secret_token_hash_and_login_columns_are_not_part_of_the_user_row_additions(): void
    {
        $row = $this->userRow($this->pull('1.1.4'));
        $this->assertArrayNotHasKey('token_hash', $row);
    }

    public function test_an_old_app_pushing_a_user_does_not_erase_the_recovery_email(): void
    {
        $user = User::where('email', 'clin@compat.test')->first();
        $user->forceFill(['recovery_email' => 'real@example.org', 'recovery_email_verified_at' => now()])->save();
        $row = (array) \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->first();
        unset($row['recovery_email'], $row['recovery_email_verified_at'], $row['facility_id'], $row['testing_laboratory_id']);
        $row['phone'] = '+211999000222';
        $row['last_modified_at'] = now()->addMinute()->toDateTimeString();

        foreach (['1.1.2', '1.1.3'] as $version) {
            $this->postJson('/api/v1/sync/push', [
                'device_id' => 'old-computer', 'timestamp' => now()->toIso8601String(), 'tables' => ['users' => [$row]],
            ], ['X-Sync-Token' => self::TOKEN, 'X-Client-Version' => $version])->assertOk();
        }

        $user->refresh();
        $this->assertSame('+211999000222', $user->phone);
        $this->assertSame('real@example.org', $user->recovery_email);
        $this->assertNotNull($user->recovery_email_verified_at);
    }
}