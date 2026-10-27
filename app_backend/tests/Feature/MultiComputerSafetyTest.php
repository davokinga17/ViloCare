<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\County;
use App\Models\Facility;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\State;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\DueReminderService;
use App\Services\SyncService;
use App\Services\SyncStateStore;
use App\Support\DeviceIdentity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Release 1.1.2: several clinic computers sync the same patients, so only one place may send SMS reminders,
 * every installation needs its own ID, and the central server must not show clinic-only sync health.
 */
class MultiComputerSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Patient $patient;

    private string $envDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Settings pages write to a throwaway .env, never the real one.
        $this->envDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vilocare-env-'.uniqid();
        mkdir($this->envDir);
        file_put_contents($this->envDir.DIRECTORY_SEPARATOR.'.env', "APP_NAME=Test\n");
        $this->app->useEnvironmentPath($this->envDir);

        $state = State::create(['name' => 'Eastern Equatoria', 'code' => 'EES']);
        $county = County::create(['name' => 'Kapoeta South County', 'code' => 'KPS', 'state_id' => $state->id]);
        $facility = Facility::create(['name' => 'Kapoeta Civil Hospital', 'code' => 'KCH-001', 'county_id' => $county->id]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@vilocare.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => bcrypt('Admin@123456'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->patient = Patient::create([
            'art_number' => 'ART-0001',
            'first_name' => 'Alice',
            'last_name' => 'Tester',
            'sex' => 'Female',
            'age' => 28,
            'phone' => '+254722000001',
            'art_start_date' => '2023-01-15',
            'state_id' => $state->id,
            'county_id' => $county->id,
            'facility_id' => $facility->id,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->envDir.DIRECTORY_SEPARATOR.'.env');
        @rmdir($this->envDir);
        parent::tearDown();
    }

    private function dueSoon(): void
    {
        ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-01',
            'result_date' => '2026-01-01',
            'result_value' => 20,
            'is_suppressed' => true,
            'next_due_date' => now()->addDays(4)->toDateString(),
            'lab_name' => 'National Lab',
            'sample_type' => 'Plasma',
        ]);
    }

    private function scheduled(string $command): \Illuminate\Console\Scheduling\Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains($event->command, $command)) {
                return $event;
            }
        }
        $this->fail("{$command} is not scheduled.");
    }

    // ---- SMS only on the central server ----

    public function test_sms_commands_are_not_scheduled_on_a_clinic_computer_by_default(): void
    {
        config(['sync.role' => 'clinic', 'sync.sends_sms' => false]);

        $this->assertFalse($this->scheduled('sms:send-due-reminders')->filtersPass($this->app));
        $this->assertFalse($this->scheduled('sms:process-queue')->filtersPass($this->app));
    }

    public function test_sms_commands_are_scheduled_on_the_central_server(): void
    {
        config(['sync.role' => 'central', 'sync.sends_sms' => false]);

        $this->assertTrue($this->scheduled('sms:send-due-reminders')->filtersPass($this->app));
        $this->assertTrue($this->scheduled('sms:process-queue')->filtersPass($this->app));
    }

    public function test_clinic_can_opt_in_to_sending_sms(): void
    {
        config(['sync.role' => 'clinic', 'sync.sends_sms' => true]);

        $this->assertTrue($this->scheduled('sms:send-due-reminders')->filtersPass($this->app));
        $this->assertTrue($this->scheduled('sms:process-queue')->filtersPass($this->app));
    }

    public function test_commands_send_nothing_on_a_clinic_computer_with_the_setting_off(): void
    {
        config(['sync.role' => 'clinic', 'sync.sends_sms' => false]);
        $this->fakeSmsGateway();
        $this->dueSoon();
        NotificationLog::create([
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'custom',
            'message_body' => 'Hello Alice, routine wellness check-up.',
            'status' => 'queued',
            'provider' => 'africastalking',
        ]);

        $this->artisan('sms:send-due-reminders --days=7')->expectsOutputToContain('does not send SMS reminders')->assertExitCode(0);
        $this->artisan('sms:process-queue')->expectsOutputToContain('does not send SMS')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, NotificationLog::count());
        $this->assertSame('queued', NotificationLog::first()->status);
    }

    public function test_clinic_sync_does_not_flush_the_sms_queue_unless_allowed(): void
    {
        $this->fakeSmsGateway();
        NotificationLog::create([
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'custom',
            'message_body' => 'Hello Alice, routine wellness check-up.',
            'status' => 'queued',
            'provider' => 'africastalking',
        ]);

        config(['sync.role' => 'clinic', 'sync.sends_sms' => false]);
        $this->assertSame(0, app(SyncService::class)->flushOfflineSmsQueue());
        Http::assertNothingSent();

        config(['sync.sends_sms' => true]);
        $this->assertSame(1, app(SyncService::class)->flushOfflineSmsQueue());
    }

    // ---- duplicate guard ----

    public function test_two_simultaneous_reminder_runs_send_only_one_sms(): void
    {
        $this->fakeSmsGateway();
        $this->dueSoon();

        // Two runs (two instances, as two processes would have) both read the candidate list and both pass the
        // read-only "already reminded?" check before either has sent anything.
        $runA = app(DueReminderService::class);
        $runB = app(DueReminderService::class);
        $itemA = $runA->candidates(7)->first();
        $itemB = $runB->candidates(7)->first();
        $this->assertNull($runA->skipReason($itemA));
        $this->assertNull($runB->skipReason($itemB));

        $first = $runA->send($itemA);
        $second = $runB->send($itemB);

        $this->assertNotNull($first);
        $this->assertNull($second, 'the losing run must send nothing');
        $this->assertSame(1, NotificationLog::where('category', 'due_date_reminder')->count());
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('sms_reminder_claims')->count());
    }

    public function test_reminder_claim_is_a_unique_key_in_the_database(): void
    {
        $row = ['patient_id' => $this->patient->id, 'due_date' => '2026-11-01', 'claimed_at' => now()];
        DB::table('sms_reminder_claims')->insert($row);

        $this->assertSame(0, DB::table('sms_reminder_claims')->insertOrIgnore($row));
        $this->assertSame(1, DB::table('sms_reminder_claims')->count());

        // A different due date for the same patient is a different reminder.
        $this->assertSame(1, DB::table('sms_reminder_claims')->insertOrIgnore(['due_date' => '2026-12-01'] + $row));
    }

    public function test_failed_reminder_releases_the_claim_so_it_can_be_retried(): void
    {
        // No gateway credentials: the attempt is logged as failed, nothing is delivered.
        $this->dueSoon();
        $service = app(DueReminderService::class);

        $log = $service->send($service->candidates(7)->first());

        $this->assertSame('failed', $log->status);
        $this->assertSame(0, DB::table('sms_reminder_claims')->count());
    }

    public function test_command_run_twice_sends_once(): void
    {
        $this->fakeSmsGateway();
        $this->dueSoon();

        $this->artisan('sms:send-due-reminders --days=7')->assertExitCode(0);
        $this->artisan('sms:send-due-reminders --days=7')->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertSame(1, NotificationLog::where('category', 'due_date_reminder')->count());
    }

    public function test_queued_message_held_by_another_run_is_not_sent_twice(): void
    {
        $this->fakeSmsGateway();
        $log = NotificationLog::create([
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'custom',
            'message_body' => 'Hello Alice, routine wellness check-up.',
            'status' => 'queued',
            'provider' => 'africastalking',
        ]);

        $lock = \Illuminate\Support\Facades\Cache::lock('sms-dispatch:'.$log->id, 300);
        $this->assertTrue($lock->get());

        $this->assertNull(app(\App\Services\SmsService::class)->dispatchQueued($log));
        Http::assertNothingSent();

        $lock->release();
        $this->assertNotNull(app(\App\Services\SmsService::class)->dispatchQueued($log));
        $this->assertNull(app(\App\Services\SmsService::class)->dispatchQueued($log), 'already sent: a second dispatch does nothing');
        Http::assertSentCount(1);
    }

    // ---- device ID ----

    public function test_install_with_the_shared_default_gets_one_generated_id_that_stays_stable(): void
    {
        config(['sync.role' => 'clinic', 'sync.device_id' => DeviceIdentity::DEFAULT_ID]);

        $first = (new SyncService)->deviceId();
        $second = (new SyncService)->deviceId();

        $this->assertNotSame(DeviceIdentity::DEFAULT_ID, $first);
        $this->assertMatchesRegularExpression('/^clinic-[a-z0-9]{8}$/', $first);
        $this->assertSame($first, $second);
        $this->assertSame($first, (new SyncStateStore)->get('device_id'));
    }

    public function test_two_installations_do_not_share_an_id(): void
    {
        $this->assertNotSame(DeviceIdentity::generate(), DeviceIdentity::generate());
    }

    public function test_an_id_set_at_setup_is_kept(): void
    {
        config(['sync.role' => 'clinic', 'sync.device_id' => 'kapoeta-front-desk']);

        $this->assertSame('kapoeta-front-desk', (new SyncService)->deviceId());
        $this->assertNull((new SyncStateStore)->get('device_id'));
    }

    public function test_sync_center_shows_the_device_id(): void
    {
        config(['sync.role' => 'clinic', 'sync.device_id' => 'kapoeta-front-desk']);

        $this->actingAs($this->admin)->get(route('sync.index'))->assertOk()->assertSee('kapoeta-front-desk');
    }

    public function test_admin_can_edit_device_id_and_sms_setting(): void
    {
        config(['sync.role' => 'clinic']);

        $this->actingAs($this->admin)->get(route('settings.sync.edit'))
            ->assertOk()->assertSee('This computer sends SMS reminders')->assertSee('name="device_id"', false);

        $this->actingAs($this->admin)->put(route('settings.device.update'), ['device_id' => 'juba-clinic-2', 'sends_sms' => '1'])
            ->assertRedirect(route('settings.sync.edit'));

        $env = file_get_contents($this->envDir.DIRECTORY_SEPARATOR.'.env');
        $this->assertStringContainsString('VILOCARE_DEVICE_ID="juba-clinic-2"', $env);
        $this->assertStringContainsString('VILOCARE_SENDS_SMS="true"', $env);

        $this->actingAs($this->admin)->put(route('settings.device.update'), ['device_id' => 'juba-clinic-2'])->assertRedirect();
        $this->assertStringContainsString('VILOCARE_SENDS_SMS="false"', file_get_contents($this->envDir.DIRECTORY_SEPARATOR.'.env'));
    }

    public function test_device_id_rejects_the_shared_default_and_unsafe_characters(): void
    {
        config(['sync.role' => 'clinic']);

        foreach ([DeviceIdentity::DEFAULT_ID, 'has space', 'quo"te', 'ab', 'back\\slash'] as $bad) {
            $this->actingAs($this->admin)->from(route('settings.sync.edit'))
                ->put(route('settings.device.update'), ['device_id' => $bad])
                ->assertSessionHasErrors('device_id');
        }
        $this->assertStringNotContainsString('VILOCARE_DEVICE_ID', file_get_contents($this->envDir.DIRECTORY_SEPARATOR.'.env'));
    }

    public function test_only_admins_can_change_device_settings_and_central_cannot(): void
    {
        $clinician = User::create([
            'name' => 'Clin', 'email' => 'clin@vilocare.org', 'role' => UserRole::CLINICIAN,
            'password' => bcrypt('Password-12345'), 'must_change_password' => false, 'is_active' => true,
        ]);
        $this->actingAs($clinician)->put(route('settings.device.update'), ['device_id' => 'juba-clinic-2'])->assertForbidden();

        config(['sync.role' => 'central']);
        $this->actingAs($this->admin)->put(route('settings.device.update'), ['device_id' => 'juba-clinic-2'])->assertForbidden();
    }

    public function test_sync_requests_carry_the_device_id(): void
    {
        config(['sync.role' => 'clinic', 'sync.device_id' => 'kapoeta-front-desk', 'sync.remote_url' => 'https://cloud.example.test/api/v1/sync']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        app(SyncService::class)->testConnection('https://cloud.example.test/api/v1/sync', 'tok-12345678');

        Http::assertSent(fn ($request) => $request->hasHeader('X-Device-Id', 'kapoeta-front-desk'));
    }

    // ---- central Sync Center ----

    public function test_central_sync_center_hides_clinic_only_health_and_lists_devices(): void
    {
        config(['sync.role' => 'central']);
        $service = app(SyncService::class);
        $service->recordClinicContact('kapoeta-front-desk', '1.1.2');
        $service->recordClinicContact('juba-clinic-2', '1.1.1');

        $this->actingAs($this->admin)->get(route('sync.index'))
            ->assertOk()
            ->assertDontSee('Pending Local Changes')
            ->assertDontSee('Auto-sync stalled')
            ->assertDontSee('Auto-Sync Active')
            ->assertDontSee('Last Successful Sync')
            ->assertSee('Last clinic sync received')
            ->assertSee('kapoeta-front-desk')
            ->assertSee('juba-clinic-2')
            ->assertSee('1.1.2');
    }

    public function test_clinic_sync_center_keeps_its_own_health_widgets(): void
    {
        config(['sync.role' => 'clinic']);

        $this->actingAs($this->admin)->get(route('sync.index'))
            ->assertOk()
            ->assertSee('Pending Local Changes')
            ->assertSee('Last Successful Sync')
            ->assertDontSee('Last clinic sync received');
    }

    public function test_heartbeat_is_recorded_per_device_on_every_sync_request(): void
    {
        config(['sync.role' => 'central', 'sync.api_token' => 'central-token-1234']);
        $headers = ['X-Sync-Token' => 'central-token-1234'];

        $this->withHeaders($headers + ['X-Device-Id' => 'kapoeta-front-desk', 'X-Client-Version' => '1.1.2'])
            ->getJson('/api/v1/sync/pull')->assertOk();
        $this->withHeaders($headers + ['X-Device-Id' => 'juba-clinic-2'])
            ->getJson('/api/v1/sync/counts')->assertOk();

        $rows = DB::table('clinic_devices')->orderBy('device_id')->get()->keyBy('device_id');
        $this->assertCount(2, $rows);
        $this->assertSame('1.1.2', $rows['kapoeta-front-desk']->app_version);
        $this->assertNotNull($rows['juba-clinic-2']->last_contact_at);

        $this->travel(5)->minutes();
        $this->withHeaders($headers + ['X-Device-Id' => 'kapoeta-front-desk', 'X-Client-Version' => '1.1.2'])
            ->getJson('/api/v1/sync/pull')->assertOk();
        $this->assertSame(2, DB::table('clinic_devices')->count(), 'a repeat contact updates the row, it does not add one');
    }

    // ---- one-time mark-synced command ----

    public function test_mark_central_synced_clears_pending_rows_on_the_central_server_only(): void
    {
        config(['sync.role' => 'central']);
        $this->assertSame(SyncStatus::PENDING->value, $this->patient->fresh()->sync_status->value ?? $this->patient->fresh()->sync_status);

        $this->artisan('vilocare:mark-central-synced --force')->expectsOutputToContain('Marked')->assertExitCode(0);

        $this->assertSame(0, Patient::where('sync_status', SyncStatus::PENDING->value)->count());
        $this->assertSame(0, app(SyncService::class)->getSyncStats()['total_pending']);
    }

    public function test_mark_central_synced_refuses_on_a_clinic_computer(): void
    {
        config(['sync.role' => 'clinic']);

        $this->artisan('vilocare:mark-central-synced --force')->expectsOutputToContain('not the central server')->assertExitCode(1);

        $this->assertGreaterThan(0, Patient::where('sync_status', SyncStatus::PENDING->value)->count());
    }
}
