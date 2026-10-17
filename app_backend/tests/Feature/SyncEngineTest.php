<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\County;
use App\Models\Facility;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\State;
use App\Models\SyncConflict;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\SyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncEngineTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $clinician;
    protected State $state;
    protected County $county;
    protected Facility $facility;
    protected string $syncToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncToken = config('sync.api_token');

        $this->state = State::create(['name' => 'Eastern Equatoria', 'code' => 'EES']);
        $this->county = County::create(['name' => 'Kapoeta South County', 'code' => 'KPS', 'state_id' => $this->state->id]);
        $this->facility = Facility::create(['name' => 'Kapoeta Civil Hospital', 'code' => 'KCH-001', 'county_id' => $this->county->id]);

        $this->admin = User::create([
            'name' => 'Admin System',
            'email' => 'admin@vilocare.org',
            'phone' => '+254711000001',
            'role' => UserRole::ADMINISTRATOR,
            'password' => bcrypt('Admin@123456'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

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

    public function test_offline_record_creation_assigns_uuid_and_pending_sync_status(): void
    {
        $patient = Patient::create([
            'art_number' => 'ART-OFFLINE-001',
            'first_name' => 'David',
            'last_name' => 'Kimani',
            'sex' => 'Male',
            'age' => 35,
            'phone' => '+254700111222',
            'art_start_date' => '2024-05-10',
            'state_id' => $this->state->id,
            'county_id' => $this->county->id,
            'facility_id' => $this->facility->id,
            'created_by' => $this->clinician->id,
        ]);

        $this->assertNotEmpty($patient->id);
        $this->assertTrue(Str::isUuid($patient->id));
        $this->assertEquals(SyncStatus::PENDING->value, $patient->sync_status);
        $this->assertNotNull($patient->last_modified_at);
        $this->assertNotNull($patient->created_at);
        $this->assertNotNull($patient->updated_at);
    }

    public function test_sync_api_rejects_unauthorized_requests(): void
    {
        $response = $this->postJson('/api/v1/sync/push', [
            'device_id' => 'test-device',
            'timestamp' => now()->toIso8601String(),
            'tables' => [],
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_sync_api_push_inserts_new_offline_records_on_server(): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'device_id' => 'clinic-laptop-01',
            'timestamp' => now()->toIso8601String(),
            'tables' => [
                'patients' => [
                    [
                        'id' => $uuid,
                        'art_number' => 'ART-SYNC-001',
                        'first_name' => 'Grace',
                        'last_name' => 'Muthoni',
                        'sex' => 'Female',
                        'age' => 29,
                        'phone' => '+254733444555',
                        'art_start_date' => '2023-11-20',
                        'state_id' => $this->state->id,
                        'county_id' => $this->county->id,
                        'facility_id' => $this->facility->id,
                        'status' => 'active',
                        'sync_status' => 'pending',
                        'last_modified_at' => now()->toIso8601String(),
                        'created_at' => now()->toIso8601String(),
                        'updated_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders(['X-Sync-Token' => $this->syncToken])
            ->postJson('/api/v1/sync/push', $payload);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('patients', [
            'id' => $uuid,
            'art_number' => 'ART-SYNC-001',
            'sync_status' => SyncStatus::SYNCED->value,
        ]);
    }

    public function test_lww_conflict_resolution_client_newer_wins(): void
    {
        $patientId = (string) Str::uuid();
        
        // Initial state on server modified 1 hour ago
        $serverTime = now()->subHour();
        Patient::create([
            'id' => $patientId,
            'art_number' => 'ART-LWW-001',
            'first_name' => 'Original',
            'last_name' => 'ServerName',
            'sex' => 'Male',
            'age' => 40,
            'phone' => '+254700000000',
            'art_start_date' => '2023-01-01',
            'state_id' => $this->state->id,
            'county_id' => $this->county->id,
            'facility_id' => $this->facility->id,
            'sync_status' => 'synced',
            'last_modified_at' => $serverTime,
        ]);

        // Incoming client edit modified 10 minutes ago (NEWER)
        $clientTime = now()->subMinutes(10);
        $payload = [
            'device_id' => 'clinic-laptop-01',
            'timestamp' => now()->toIso8601String(),
            'tables' => [
                'patients' => [
                    [
                        'id' => $patientId,
                        'art_number' => 'ART-LWW-001',
                        'first_name' => 'UpdatedByClient',
                        'last_name' => 'ClientWins',
                        'sex' => 'Male',
                        'age' => 41,
                        'phone' => '+254799999999',
                        'art_start_date' => '2023-01-01',
                        'state_id' => $this->state->id,
                        'county_id' => $this->county->id,
                        'facility_id' => $this->facility->id,
                        'sync_status' => 'pending',
                        'last_modified_at' => $clientTime->toIso8601String(),
                        'created_at' => $serverTime->toIso8601String(),
                        'updated_at' => $clientTime->toIso8601String(),
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders(['X-Sync-Token' => $this->syncToken])
            ->postJson('/api/v1/sync/push', $payload);

        $response->assertOk();

        // Verify client changes overwrite server
        $this->assertDatabaseHas('patients', [
            'id' => $patientId,
            'first_name' => 'UpdatedByClient',
            'last_name' => 'ClientWins',
            'phone' => '+254799999999',
            'age' => 41,
            'sync_status' => SyncStatus::SYNCED->value,
        ]);

        // Zero conflict logged since client won cleanly
        $this->assertEquals(0, SyncConflict::where('record_id', $patientId)->count());
    }

    public function test_lww_conflict_resolution_server_newer_wins_and_logs_conflict(): void
    {
        $patientId = (string) Str::uuid();

        // Server was updated 5 minutes ago (NEWER)
        $serverTime = now()->subMinutes(5);
        $serverPatient = Patient::create([
            'id' => $patientId,
            'art_number' => 'ART-LWW-002',
            'first_name' => 'ServerAuthoritative',
            'last_name' => 'ServerWins',
            'sex' => 'Female',
            'age' => 25,
            'phone' => '+254711111111',
            'art_start_date' => '2024-01-01',
            'state_id' => $this->state->id,
            'county_id' => $this->county->id,
            'facility_id' => $this->facility->id,
            'sync_status' => 'synced',
            'last_modified_at' => $serverTime,
        ]);

        // Incoming client edit was made 30 minutes ago (OLDER)
        $clientTime = now()->subMinutes(30);
        $payload = [
            'device_id' => 'clinic-laptop-01',
            'timestamp' => now()->toIso8601String(),
            'tables' => [
                'patients' => [
                    [
                        'id' => $patientId,
                        'art_number' => 'ART-LWW-002',
                        'first_name' => 'StaleClientEdit',
                        'last_name' => 'ShouldLose',
                        'sex' => 'Female',
                        'age' => 24,
                        'phone' => '+254722222222',
                        'art_start_date' => '2024-01-01',
                        'state_id' => $this->state->id,
                        'county_id' => $this->county->id,
                        'facility_id' => $this->facility->id,
                        'sync_status' => 'pending',
                        'last_modified_at' => $clientTime->toIso8601String(),
                        'created_at' => now()->subDays(1)->toIso8601String(),
                        'updated_at' => $clientTime->toIso8601String(),
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders(['X-Sync-Token' => $this->syncToken])
            ->postJson('/api/v1/sync/push', $payload);

        $response->assertOk();

        // Verify server retained its newer authoritative data
        $this->assertDatabaseHas('patients', [
            'id' => $patientId,
            'first_name' => 'ServerAuthoritative',
            'last_name' => 'ServerWins',
            'phone' => '+254711111111',
        ]);

        // Verify conflict was recorded in sync_conflicts table
        $this->assertDatabaseHas('sync_conflicts', [
            'table_name' => 'patients',
            'record_id' => $patientId,
            'resolution_strategy' => 'last_write_wins',
        ]);
    }

    public function test_sync_api_pull_returns_updated_records(): void
    {
        $patient = Patient::create([
            'art_number' => 'ART-PULL-001',
            'first_name' => 'Samuel',
            'last_name' => 'Otieno',
            'sex' => 'Male',
            'age' => 38,
            'phone' => '+254755666777',
            'art_start_date' => '2024-02-01',
            'state_id' => $this->state->id,
            'county_id' => $this->county->id,
            'facility_id' => $this->facility->id,
            'sync_status' => 'synced',
            'last_modified_at' => now(),
        ]);

        $sinceTime = urlencode(now()->subHour()->toIso8601String());

        $response = $this->withHeaders(['X-Sync-Token' => $this->syncToken])
            ->getJson("/api/v1/sync/pull?since={$sinceTime}");

        $response->assertOk()
            ->assertJson(['success' => true]);

        $responseData = $response->json();
        $this->assertGreaterThanOrEqual(1, count($responseData['tables']['patients']));
    }

    public function test_sync_service_get_pending_local_payload(): void
    {
        $patient = Patient::create([
            'art_number' => 'ART-LOCAL-001',
            'first_name' => 'Local',
            'last_name' => 'Pending',
            'sex' => 'Female',
            'age' => 22,
            'art_start_date' => '2024-01-01',
            'state_id' => $this->state->id,
            'county_id' => $this->county->id,
            'facility_id' => $this->facility->id,
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        $syncService = app(SyncService::class);
        $payload = $syncService->getPendingLocalPayload();

        $this->assertGreaterThanOrEqual(1, $payload['total_records']);
        $this->assertNotEmpty($payload['tables']['patients']);
    }

    public function test_sync_center_web_routes(): void
    {
        // 1. Index page
        $this->actingAs($this->clinician)->get(route('sync.index'))
            ->assertOk()
            ->assertSee('Sync Center', false)
            ->assertSee('Synchronized Clinical Tables', false);

        // 2. Conflicts list page
        $this->actingAs($this->clinician)->get(route('sync.conflicts'))
            ->assertOk()
            ->assertSee('Sync Conflict History', false);

        // 3. Trigger manual sync
        $this->actingAs($this->clinician)->post(route('sync.trigger'))
            ->assertRedirect(route('sync.index'));

        // 4. Resolve conflict
        $conflict = SyncConflict::create([
            'table_name' => 'patients',
            'record_id' => (string) Str::uuid(),
            'local_payload' => ['first_name' => 'Local'],
            'remote_payload' => ['first_name' => 'Remote'],
            'resolution_strategy' => 'last_write_wins',
        ]);

        $this->actingAs($this->clinician)->post(route('sync.conflicts.resolve', $conflict), [
            'strategy' => 'mark_resolved',
            'notes' => 'Reviewed by Dr. Jane.',
        ])->assertRedirect(route('sync.conflicts'));

        $conflict->refresh();
        $this->assertNotNull($conflict->resolved_at);
        $this->assertEquals($this->clinician->id, $conflict->resolved_by);
    }

    public function test_sync_cli_commands(): void
    {
        $this->artisan('vilocare:sync-status')
            ->expectsOutputToContain('ViLoCare Synchronization Status')
            ->assertExitCode(0);

        // Standalone mode is now an explicit opt-in, never inferred from the URL.
        config(['sync.standalone' => true]);

        $this->artisan('vilocare:sync --url=http://127.0.0.1:8000/api/v1/sync')
            ->expectsOutputToContain('Starting ViLoCare full synchronization cycle...')
            ->assertExitCode(0);
    }

    public function test_loopback_endpoint_without_standalone_flag_is_reported_not_silently_synced(): void
    {
        $patient = Patient::create([
            'art_number' => 'ART-LOOP-001',
            'first_name' => 'Loop',
            'last_name' => 'Back',
            'sex' => 'Female',
            'age' => 30,
            'art_start_date' => '2024-01-01',
            'state_id' => $this->state->id,
            'county_id' => $this->county->id,
            'facility_id' => $this->facility->id,
        ]);

        $result = app(SyncService::class)->runFullSync('http://127.0.0.1:8000/api/v1/sync');

        $this->assertFalse($result['success']);
        $this->assertEquals('config_error', $result['error_type']);
        $this->assertEquals(SyncStatus::PENDING->value, $patient->fresh()->sync_status);
    }

    public function test_browser_triggered_auto_sync_endpoint_no_longer_exists(): void
    {
        config(['sync.standalone' => true]);

        // The browser-triggered endpoint is gone: only the scheduler and the "Sync now" button start syncs.
        $this->actingAs($this->clinician)
            ->postJson('/sync/auto', ['reason' => 'auto_background_sync'])
            ->assertNotFound();
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('sync.auto'));
        $this->assertSame(0, $this->backgroundSyncStarts);
    }

    public function test_sync_status_check_endpoint_returns_json_metrics(): void
    {
        $response = $this->actingAs($this->clinician)
            ->getJson(route('sync.status-check'));

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'stats' => [
                    'tables',
                    'total_pending',
                    'total_synced',
                    'total_conflicts',
                ],
                'server_time',
            ]);

        $this->assertTrue($response->json('success'));
    }

    public function test_manual_sync_trigger_supports_ajax_json_response(): void
    {
        $response = $this->actingAs($this->clinician)
            ->postJson(route('sync.trigger'));

        $response->assertOk()
            ->assertJsonStructure(['success', 'started', 'message', 'run' => ['running', 'phase', 'last_result']]);
    }

    public function test_sync_service_resolves_bundled_ca_options(): void
    {
        $syncService = app(SyncService::class);
        $options = $syncService->getHttpOptions();

        $this->assertArrayHasKey('verify', $options);
        if (file_exists(base_path('certs/cacert.pem'))) {
            $this->assertEquals(base_path('certs/cacert.pem'), $options['verify']);
        }

        config(['sync.ssl_verify' => false]);
        $disabledOptions = $syncService->getHttpOptions();
        $this->assertFalse($disabledOptions['verify']);
    }

    public function test_format_connection_error_identifies_ssl_certificate_errors(): void
    {
        $rawError = 'cURL error 60: SSL certificate problem: unable to get local issuer certificate (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://central.example.com/api/v1/sync/push';
        $formatted = SyncService::formatConnectionError($rawError);

        $this->assertEquals('ssl_certificate', $formatted['type']);
        $this->assertStringContainsString('SSL', $formatted['title']);
        $this->assertStringContainsString('safe', strtolower($formatted['action_hint']));
        $this->assertEquals($rawError, $formatted['raw_error']);
    }

    public function test_format_connection_error_identifies_network_and_timeout_errors(): void
    {
        $netError = 'cURL error 6: Could not resolve host: central.example.com';
        $formattedNet = SyncService::formatConnectionError($netError);
        $this->assertEquals('network_offline', $formattedNet['type']);

        $timeoutError = 'cURL error 28: Operation timed out after 20000 milliseconds';
        $formattedTimeout = SyncService::formatConnectionError($timeoutError);
        $this->assertEquals('timeout', $formattedTimeout['type']);
    }

    public function test_sync_index_view_displays_ssl_badge(): void
    {
        $response = $this->actingAs($this->clinician)->get(route('sync.index'));
        $response->assertOk();
        $response->assertSee('Bundled CA Active', false);
    }

    public function test_business_unique_key_reconciles_user_duplicate_email(): void
    {
        // Server already has user newstaff@vilocare.org with server UUID
        $serverId = (string) Str::uuid();
        User::create([
            'id' => $serverId,
            'name' => 'Server Intake Clerk',
            'email' => 'newstaff@vilocare.org',
            'phone' => '+211910000005',
            'role' => UserRole::DATA_CLERK,
            'password' => bcrypt('ServerPass@123'),
            'must_change_password' => true,
            'is_active' => true,
            'sync_status' => 'synced',
            'last_modified_at' => now()->subHour(),
        ]);

        // Incoming client record has DIFFERENT client UUID but SAME email
        $clientId = (string) Str::uuid();
        $clientTime = now()->subMinutes(5); // Newer edit from client

        $payload = [
            'device_id' => 'clinic-laptop-01',
            'timestamp' => now()->toIso8601String(),
            'tables' => [
                'users' => [
                    [
                        'id' => $clientId,
                        'name' => 'Client Updated Intake Clerk',
                        'email' => 'newstaff@vilocare.org',
                        'phone' => '+211910000099',
                        'role' => UserRole::DATA_CLERK->value,
                        'password' => bcrypt('ClientPass@123'),
                        'must_change_password' => false,
                        'is_active' => true,
                        'sync_status' => 'pending',
                        'last_modified_at' => $clientTime->toIso8601String(),
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders(['X-Sync-Token' => $this->syncToken])
            ->postJson('/api/v1/sync/push', $payload);

        $response->assertOk()
            ->assertJson(['success' => true]);

        // Verify the client's pushed ID is acknowledged in synced_ids
        $syncedIds = $response->json('synced_ids.users');
        $this->assertContains($clientId, $syncedIds);

        // Verify server canonical ID was preserved and not duplicated
        $this->assertEquals(1, User::where('email', 'newstaff@vilocare.org')->count());
        $user = User::where('email', 'newstaff@vilocare.org')->first();
        $this->assertEquals($serverId, $user->id);
        $this->assertEquals('Client Updated Intake Clerk', $user->name);
        $this->assertEquals('+211910000099', $user->phone);

        // Verify conflict was recorded with business_key_reconciled
        $this->assertDatabaseHas('sync_conflicts', [
            'table_name' => 'users',
            'record_id' => $clientId,
            'resolution_strategy' => 'business_key_reconciled',
        ]);
    }

    public function test_business_unique_key_reconciles_patient_duplicate_art_number(): void
    {
        $serverPatientId = (string) Str::uuid();
        Patient::create([
            'id' => $serverPatientId,
            'art_number' => 'ART-DUP-001',
            'first_name' => 'John',
            'last_name' => 'Kamau',
            'sex' => 'Male',
            'age' => 38,
            'phone' => '+254700000001',
            'art_start_date' => '2023-01-01',
            'state_id' => $this->state->id,
            'county_id' => $this->county->id,
            'facility_id' => $this->facility->id,
            'sync_status' => 'synced',
            'last_modified_at' => now()->subHour(),
        ]);

        $clientPatientId = (string) Str::uuid();
        $clientTime = now()->subMinutes(2);

        $payload = [
            'device_id' => 'clinic-laptop-01',
            'timestamp' => now()->toIso8601String(),
            'tables' => [
                'patients' => [
                    [
                        'id' => $clientPatientId,
                        'art_number' => 'ART-DUP-001',
                        'first_name' => 'John Client',
                        'last_name' => 'Kamau Updated',
                        'sex' => 'Male',
                        'age' => 39,
                        'phone' => '+254700000099',
                        'art_start_date' => '2023-01-01',
                        'state_id' => $this->state->id,
                        'county_id' => $this->county->id,
                        'facility_id' => $this->facility->id,
                        'sync_status' => 'pending',
                        'last_modified_at' => $clientTime->toIso8601String(),
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders(['X-Sync-Token' => $this->syncToken])
            ->postJson('/api/v1/sync/push', $payload);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, Patient::where('art_number', 'ART-DUP-001')->count());
        $patient = Patient::where('art_number', 'ART-DUP-001')->first();
        $this->assertEquals($serverPatientId, $patient->id);
        $this->assertEquals('John Client', $patient->first_name);

        $this->assertDatabaseHas('sync_conflicts', [
            'table_name' => 'patients',
            'record_id' => $clientPatientId,
            'resolution_strategy' => 'business_key_reconciled',
        ]);
    }

    public function test_format_connection_error_identifies_unique_violations(): void
    {
        $rawError = 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "users_email_unique" DETAIL: Key (email)=(newstaff@vilocare.org) already exists.';
        $formatted = SyncService::formatConnectionError($rawError);

        $this->assertEquals('duplicate_key_conflict', $formatted['type']);
        $this->assertStringContainsString('Duplicate', $formatted['title']);
        $this->assertStringContainsString('safeguarded', $formatted['friendly_message']);
        $this->assertStringNotContainsString('SQLSTATE', $formatted['friendly_message']);
        $this->assertEquals($rawError, $formatted['raw_error']);
    }
}


