<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\County;
use App\Models\Facility;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\State;
use App\Models\User;
use App\Models\ViralLoadResult;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DueReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $state = State::create(['name' => 'Eastern Equatoria', 'code' => 'EES']);
        $county = County::create(['name' => 'Kapoeta South County', 'code' => 'KPS', 'state_id' => $state->id]);
        $facility = Facility::create(['name' => 'Kapoeta Civil Hospital', 'code' => 'KCH-001', 'county_id' => $county->id]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@vilocare.org',
            'phone' => '+254711000001',
            'role' => UserRole::ADMINISTRATOR,
            'password' => bcrypt('Admin@123456'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->patient = $this->makePatient('ART-0001', 'Alice', '+254722000001', $state, $county, $facility);
    }

    private function makePatient(string $art, string $name, ?string $phone, ?State $state = null, ?County $county = null, ?Facility $facility = null): Patient
    {
        $facility ??= Facility::first();

        return Patient::create([
            'art_number' => $art,
            'first_name' => $name,
            'last_name' => 'Tester',
            'sex' => 'Female',
            'age' => 28,
            'phone' => $phone,
            'art_start_date' => '2023-01-15',
            'state_id' => $state?->id ?? $facility->county->state_id,
            'county_id' => $county?->id ?? $facility->county_id,
            'facility_id' => $facility->id,
            'created_by' => $this->admin->id,
        ]);
    }

    private function addResult(Patient $patient, string $sampleDate, string $nextDue): ViralLoadResult
    {
        return ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => $sampleDate,
            'result_date' => $sampleDate,
            'result_value' => 20,
            'is_suppressed' => true,
            'next_due_date' => $nextDue,
            'lab_name' => 'National Lab',
            'sample_type' => 'Plasma',
        ]);
    }

    public function test_every_scheduled_command_exists(): void
    {
        $registered = array_keys(Artisan::all());
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            // Command strings look like: 'C:\php.exe' 'artisan' sms:process-queue
            $this->assertMatchesRegularExpression('/artisan[\'"]?\s+([\w:-]+)/', $event->command);
            preg_match('/artisan[\'"]?\s+([\w:-]+)/', $event->command, $m);
            $this->assertContains($m[1], $registered, "Scheduled command '{$m[1]}' is not a registered artisan command.");
        }
    }

    public function test_patient_with_many_old_results_gets_one_reminder(): void
    {
        $soon = now()->addDays(3)->toDateString();

        // Two stale rows still pointing inside the window plus the real latest result.
        $this->addResult($this->patient, '2025-01-01', now()->addDays(1)->toDateString());
        $this->addResult($this->patient, '2025-06-01', now()->addDays(2)->toDateString());
        $this->addResult($this->patient, '2026-01-01', $soon);

        $this->artisan('sms:send-due-reminders --days=7')
            ->expectsOutputToContain('Found 1 due patients.')
            ->expectsOutputToContain('1 reminders dispatched')
            ->assertExitCode(0);

        $this->assertSame(1, NotificationLog::where('patient_id', $this->patient->id)->where('category', 'due_date_reminder')->count());
    }

    public function test_patient_whose_latest_result_is_not_due_is_not_messaged_for_old_rows(): void
    {
        $this->addResult($this->patient, '2025-01-01', now()->addDays(2)->toDateString());
        $this->addResult($this->patient, '2026-01-01', now()->addMonths(6)->toDateString());

        $this->artisan('sms:send-due-reminders --days=7')
            ->expectsOutputToContain('No patients due')
            ->assertExitCode(0);

        $this->assertSame(0, NotificationLog::count());
    }

    public function test_second_run_does_not_send_duplicate_reminder(): void
    {
        $this->fakeSmsGateway();
        $this->addResult($this->patient, '2026-01-01', now()->addDays(4)->toDateString());

        $this->artisan('sms:send-due-reminders --days=7')->assertExitCode(0);
        $this->artisan('sms:send-due-reminders --days=7')
            ->expectsOutputToContain('0 reminders dispatched, 1 skipped')
            ->assertExitCode(0);

        $this->assertSame(1, NotificationLog::where('category', 'due_date_reminder')->count());
    }

    public function test_queued_reminder_for_same_due_date_blocks_a_new_one_even_after_window(): void
    {
        $due = now()->addDays(4);
        $this->addResult($this->patient, '2026-01-01', $due->toDateString());

        NotificationLog::create([
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'due_date_reminder',
            'message_body' => 'Hello Alice, a gentle reminder that your scheduled health review at Kapoeta Civil Hospital is due on '.$due->format('M j, Y').'.',
            'status' => 'queued',
            'provider' => 'africastalking',
        ]);
        NotificationLog::query()->update(['created_at' => now()->subDays(20)]);

        $this->artisan('sms:send-due-reminders --days=7')
            ->expectsOutputToContain('0 reminders dispatched, 1 skipped')
            ->assertExitCode(0);

        $this->assertSame(1, NotificationLog::count());
    }

    public function test_failed_reminder_does_not_block_a_retry(): void
    {
        $this->addResult($this->patient, '2026-01-01', now()->addDays(4)->toDateString());

        NotificationLog::create([
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'due_date_reminder',
            'message_body' => 'x',
            'status' => 'failed',
            'error_message' => 'HTTP 500',
            'provider' => 'africastalking',
        ]);

        $this->artisan('sms:send-due-reminders --days=7')
            ->expectsOutputToContain('1 reminders dispatched')
            ->assertExitCode(0);
    }

    public function test_dry_run_lists_recipients_and_sends_nothing(): void
    {
        $this->addResult($this->patient, '2026-01-01', now()->addDays(4)->toDateString());
        $noPhone = $this->makePatient('ART-0002', 'Bea', null);
        $this->addResult($noPhone, '2026-01-01', now()->addDays(4)->toDateString());

        $this->artisan('sms:send-due-reminders --days=7 --dry-run')
            ->expectsOutputToContain('[Would send] ART-0001')
            ->expectsOutputToContain('ART-0002: no phone number')
            ->expectsOutputToContain('1 reminders would be dispatched (nothing sent), 1 skipped')
            ->assertExitCode(0);

        $this->assertSame(0, NotificationLog::count());
    }

    public function test_bulk_due_endpoint_is_patient_level_and_deduplicated(): void
    {
        $this->fakeSmsGateway();
        $this->addResult($this->patient, '2025-01-01', now()->addDays(1)->toDateString());
        $this->addResult($this->patient, '2026-01-01', now()->addDays(3)->toDateString());

        $this->actingAs($this->admin)->post(route('notifications.bulk-due'), ['days' => 7])
            ->assertRedirect(route('notifications.index'));
        $this->actingAs($this->admin)->post(route('notifications.bulk-due'), ['days' => 7])
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '0 discrete reminders') && str_contains($m, '1 skipped'));

        $this->assertSame(1, NotificationLog::where('category', 'due_date_reminder')->count());
    }

    public function test_reminder_text_stays_discreet(): void
    {
        $this->fakeSmsGateway();
        $this->addResult($this->patient, '2026-01-01', now()->addDays(4)->toDateString());

        $this->artisan('sms:send-due-reminders --days=7')->assertExitCode(0);

        $log = NotificationLog::where('category', 'due_date_reminder')->firstOrFail();
        $this->assertNotSame('failed', $log->status);
        $this->assertDoesNotMatchRegularExpression('/\b(hiv|art|viral|load|regimen|suppress)/i', $log->message_body);
    }
}
