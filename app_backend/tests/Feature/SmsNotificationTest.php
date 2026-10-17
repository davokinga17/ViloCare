<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\County;
use App\Models\EacEpisode;
use App\Models\EacSession;
use App\Models\Facility;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\State;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class SmsNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $clinician;
    protected Facility $facility;
    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $state = State::create(['name' => 'Eastern Equatoria', 'code' => 'EES']);
        $county = County::create(['name' => 'Kapoeta South County', 'code' => 'KPS', 'state_id' => $state->id]);
        $this->facility = Facility::create(['name' => 'Kapoeta Civil Hospital', 'code' => 'KCH-001', 'county_id' => $county->id]);

        $this->admin = User::create([
            'name' => 'Admin User',
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

        $this->patient = Patient::create([
            'art_number' => 'ART-NBO-0001',
            'first_name' => 'Alice',
            'last_name' => 'Wanjiku',
            'sex' => 'Female',
            'age' => 28,
            'phone' => '+254722000001',
            'art_start_date' => '2023-01-15',
            'state_id' => $state->id,
            'county_id' => $county->id,
            'facility_id' => $this->facility->id,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_discreet_privacy_policy_blocks_forbidden_medical_words(): void
    {
        $smsService = app(SmsService::class);

        $forbiddenPhrases = [
            'Hello Alice, your HIV test results are ready.',
            'Reminder: Your viral load test is scheduled tomorrow.',
            'Please come collect your ARV refill.',
            'Your CD4 count has been received.',
            'Notice: Patient regimen switch failure.',
        ];

        foreach ($forbiddenPhrases as $badPhrase) {
            $notification = $smsService->send('+254722000001', $badPhrase, 'test');
            $this->assertEquals('failed', $notification->status);
            $this->assertStringContainsString('Privacy Violation', $notification->error_message);
            $this->assertDatabaseHas('notification_logs', [
                'id' => $notification->id,
                'status' => 'failed',
            ]);
        }
    }

    public function test_user_provided_phone_number_formatting_and_dispatch(): void
    {
        $this->fakeSmsGateway();
        $smsService = app(SmsService::class);
        $userPhone = '+211910994073';

        $patientSouthSudan = Patient::create([
            'art_number' => 'ART-SS-0001',
            'first_name' => 'Sarah',
            'last_name' => 'Namubiru',
            'sex' => 'Female',
            'age' => 28,
            'phone' => $userPhone,
            'art_start_date' => '2023-05-10',
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'created_by' => $this->admin->id,
        ]);

        $message = "Hello {$patientSouthSudan->first_name}, your routine wellness review report is ready for collection.";
        $notification = $smsService->send($userPhone, $message, 'result_ready', $patientSouthSudan);

        $this->assertInstanceOf(NotificationLog::class, $notification);
        $this->assertEquals('+211910994073', $notification->recipient_phone);
        $this->assertEquals('sent', $notification->status); // Mocked/simulated delivery in testing
        $this->assertEquals('result_ready', $notification->category);

        $this->assertDatabaseHas('notification_logs', [
            'id' => $notification->id,
            'recipient_phone' => '+211910994073',
            'status' => 'sent',
        ]);
    }

    public function test_missing_phone_creates_failed_notification_log(): void
    {
        $smsService = app(SmsService::class);

        $patientNoPhone = Patient::create([
            'art_number' => 'ART-NO-PHONE',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'sex' => 'Male',
            'age' => 35,
            'phone' => null,
            'art_start_date' => '2023-01-01',
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'created_by' => $this->admin->id,
        ]);

        $notification = $smsService->sendResultReadyNotification($patientNoPhone);

        $this->assertNotNull($notification);
        $this->assertEquals('failed', $notification->status);
        $this->assertStringContainsString('Missing or invalid recipient phone number', $notification->error_message);
        $this->assertDatabaseHas('notification_logs', [
            'id' => $notification->id,
            'patient_id' => $patientNoPhone->id,
            'status' => 'failed',
        ]);
    }

    public function test_discreet_neutral_phrases_are_accepted(): void
    {
        $smsService = app(SmsService::class);

        $goodMessage = "Hello Alice, your routine wellness lab report is ready for collection at the clinic.";
        $notification = $smsService->send('+254722000001', $goodMessage, 'result_ready', $this->patient);

        $this->assertInstanceOf(NotificationLog::class, $notification);
        $this->assertEquals('+254722000001', $notification->recipient_phone);
        $this->assertEquals('result_ready', $notification->category);
        $this->assertDatabaseHas('notification_logs', [
            'id' => $notification->id,
            'patient_id' => $this->patient->id,
            'category' => 'result_ready',
        ]);
    }

    public function test_viral_load_result_entry_triggers_result_ready_sms(): void
    {
        $response = $this->actingAs($this->clinician)->post(route('viral-load.store'), [
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-08-01',
            'result_date' => '2026-08-05',
            'result_value' => 50,
            'sample_type' => 'Plasma',
            'lab_name' => 'Kapoeta Civil Hospital',
        ]);

        $response->assertRedirect();
        
        $this->assertDatabaseHas('notification_logs', [
            'patient_id' => $this->patient->id,
            'category' => 'result_ready',
            'recipient_phone' => '+254722000001',
        ]);
    }

    public function test_appointment_creation_triggers_appointment_reminder_sms(): void
    {
        $response = $this->actingAs($this->clinician)->post(route('appointments.store'), [
            'patient_id' => $this->patient->id,
            'appointment_date' => now()->addDays(7)->toDateString(),
            'appointment_time' => '10:00 AM',
            'reason' => 'Routine Clinical Review',
        ]);

        $response->assertRedirect(route('appointments.index'));

        $this->assertDatabaseHas('notification_logs', [
            'patient_id' => $this->patient->id,
            'category' => 'appointment_reminder',
            'recipient_phone' => '+254722000001',
        ]);
    }

    public function test_eac_session_update_triggers_eac_reminder_sms(): void
    {
        // Create high VL result to trigger EAC episode
        $this->actingAs($this->clinician)->post(route('viral-load.store'), [
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-08-01',
            'result_date' => '2026-08-05',
            'result_value' => 5000,
            'sample_type' => 'Plasma',
            'lab_name' => 'Kapoeta Civil Hospital',
        ]);

        $episode = EacEpisode::where('patient_id', $this->patient->id)->first();
        $session1 = $episode->sessions()->where('session_number', 1)->first();

        $nextDate = now()->addDays(14)->toDateString();

        $response = $this->actingAs($this->clinician)->put(route('eac.sessions.update', $session1), [
            'session_date' => now()->toDateString(),
            'action_plan' => 'Adherence pillbox plan discussed with patient.',
            'next_session_date' => $nextDate,
        ]);

        $response->assertRedirect(route('eac.show', $episode));

        $this->assertDatabaseHas('notification_logs', [
            'patient_id' => $this->patient->id,
            'category' => 'eac_session_reminder',
            'recipient_phone' => '+254722000001',
        ]);
    }

    public function test_offline_failed_dispatch_queues_message(): void
    {
        // Configure fake API key that simulates network error
        config(['services.africastalking.api_key' => 'fake_api_key']);
        Http::fake([
            'https://api.sandbox.africastalking.com/*' => Http::response(null, 500),
        ]);

        $smsService = new SmsService();
        $notification = $smsService->send('+254722000001', 'Hello Alice, routine wellness review notice.', 'custom', $this->patient);

        $this->assertEquals('failed', $notification->status);

        // Reset config for subsequent tests
        config(['services.africastalking.api_key' => null]);
    }

    public function test_process_sms_queue_command(): void
    {
        $this->fakeSmsGateway();
        // Create queued notification
        $log = NotificationLog::create([
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'custom',
            'message_body' => 'Hello Alice, routine wellness check-up.',
            'status' => 'queued',
            'provider' => 'africastalking',
        ]);

        $this->artisan('sms:process-queue')
            ->expectsOutputToContain('Processing 1 queued SMS message(s)...')
            ->assertExitCode(0);

        $log->refresh();
        $this->assertEquals('sent', $log->status);
    }

    public function test_send_due_reminders_command(): void
    {
        // Create VL result with upcoming next_due_date within 5 days
        ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-02-01',
            'result_date' => '2026-02-05',
            'result_value' => 20,
            'is_suppressed' => true,
            'next_due_date' => now()->addDays(5)->toDateString(),
            'lab_name' => 'National Lab',
            'sample_type' => 'Plasma',
        ]);

        $this->artisan('sms:send-due-reminders --days=7')
            ->expectsOutputToContain('Due Reminders Summary: 1 reminders dispatched')
            ->assertExitCode(0);

        $this->assertDatabaseHas('notification_logs', [
            'patient_id' => $this->patient->id,
            'category' => 'due_date_reminder',
        ]);
    }

    public function test_notifications_web_management_routes(): void
    {
        // 1. Index page
        $this->actingAs($this->clinician)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('SMS Notifications', false);

        // 2. Create page
        $this->actingAs($this->clinician)->get(route('notifications.create'))
            ->assertOk()
            ->assertSee('Compose Discrete Patient SMS');

        // 3. Store valid SMS
        $response = $this->actingAs($this->clinician)->post(route('notifications.store'), [
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'custom',
            'message_body' => 'Hello Alice, your scheduled clinic review is coming up soon.',
        ]);

        $response->assertRedirect(route('notifications.index'));
        $this->assertDatabaseHas('notification_logs', [
            'recipient_phone' => '+254722000001',
            'message_body' => 'Hello Alice, your scheduled clinic review is coming up soon.',
        ]);

        // 4. Retry SMS
        $log = NotificationLog::where('recipient_phone', '+254722000001')->first();
        $this->actingAs($this->clinician)->post(route('notifications.retry', $log))
            ->assertRedirect();
    }

    public function test_facility_name_sanitization_prevents_art_privacy_violation(): void
    {
        $this->fakeSmsGateway();
        $smsService = app(SmsService::class);

        $artFacility = Facility::create([
            'name' => 'ViLoCare Main ART Centre',
            'code' => 'VLC-ART-01',
            'county_id' => $this->facility->county_id,
        ]);

        $patientArt = Patient::create([
            'art_number' => 'ART-TEST-0015',
            'first_name' => 'Hellom',
            'last_name' => 'Wanyika',
            'sex' => 'Male',
            'age' => 32,
            'phone' => '+211915967396',
            'art_start_date' => '2023-01-15',
            'facility_id' => $artFacility->id,
            'county_id' => $artFacility->county_id,
            'state_id' => $this->facility->county->state_id,
            'created_by' => $this->admin->id,
        ]);

        $notification = $smsService->sendResultReadyNotification($patientArt);

        $this->assertNotNull($notification);
        $this->assertEquals('sent', $notification->status);
        $this->assertStringContainsString('ViLoCare Main Health Centre', $notification->message_body);
        $this->assertFalse($notification->isPrivacyViolation());
    }

    public function test_dashboard_categorizes_failure_types_distinctly(): void
    {
        // 1. Create a privacy violation log
        $privacyLog = NotificationLog::create([
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'custom',
            'message_body' => 'Notice: Patient HIV viral load test result.',
            'status' => 'failed',
            'error_message' => "Privacy Violation: SMS content contains protected medical term 'hiv'. ViLoCare enforces discreet, neutral wording only.",
        ]);

        // 2. Create a gateway failure log
        $gatewayLog = NotificationLog::create([
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'custom',
            'message_body' => 'Hello Alice, routine clinic review notice.',
            'status' => 'failed',
            'error_message' => 'HTTP 500: Africa\'s Talking API Gateway internal error',
        ]);

        // 3. Create an invalid phone log
        $phoneLog = NotificationLog::create([
            'patient_id' => null,
            'recipient_phone' => 'NO_PHONE',
            'category' => 'custom',
            'message_body' => 'Hello client, routine wellness review.',
            'status' => 'failed',
            'error_message' => 'Missing or invalid recipient phone number.',
        ]);

        $this->assertTrue($privacyLog->isPrivacyViolation());
        $this->assertEquals('privacy_violation', $privacyLog->failureType());
        $this->assertFalse($privacyLog->isRetryable());

        $this->assertTrue($gatewayLog->isGatewayFailure());
        $this->assertEquals('gateway_failure', $gatewayLog->failureType());
        $this->assertTrue($gatewayLog->isRetryable());

        $this->assertTrue($phoneLog->isInvalidRecipient());
        $this->assertEquals('invalid_recipient', $phoneLog->failureType());
        $this->assertFalse($phoneLog->isRetryable());

        $response = $this->actingAs($this->clinician)->get(route('notifications.index'));
        $response->assertOk();
        $response->assertSee('Privacy / Policy Blocked');
        $response->assertSee('Gateway Failures');
    }

    public function test_privacy_violation_cannot_be_retried(): void
    {
        $privacyLog = NotificationLog::create([
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'custom',
            'message_body' => 'Notice: Patient HIV viral load test result.',
            'status' => 'failed',
            'error_message' => "Privacy Violation: SMS content contains protected medical term 'hiv'.",
        ]);

        $response = $this->actingAs($this->clinician)->post(route('notifications.retry', $privacyLog));
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $privacyLog->refresh();
        $this->assertEquals('failed', $privacyLog->status);
    }

    public function test_without_an_api_key_the_message_is_recorded_as_not_sent_with_the_reason(): void
    {
        config(['services.africastalking.api_key' => null]);
        Http::fake();

        $log = (new SmsService())->send('+254722000001', 'Hello Alice, routine wellness review notice.', 'custom', $this->patient);

        $this->assertSame('failed', $log->status);
        $this->assertSame(SmsService::NOT_CONFIGURED_REASON, $log->error_message);
        $this->assertNull($log->sent_at);
        $this->assertNull($log->provider_message_id);
        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_logs', ['id' => $log->id, 'status' => 'failed']);
        $this->assertDatabaseMissing('notification_logs', ['status' => 'sent']);
    }

    public function test_queued_messages_are_not_marked_sent_when_there_is_no_key(): void
    {
        config(['services.africastalking.api_key' => '']);
        $log = NotificationLog::create([
            'patient_id' => $this->patient->id,
            'recipient_phone' => '+254722000001',
            'category' => 'custom',
            'message_body' => 'Hello Alice, routine wellness check-up.',
            'status' => 'queued',
            'provider' => 'africastalking',
        ]);

        (new SmsService())->dispatchToProvider($log);

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertStringContainsString('Integrations', $log->fresh()->error_message);
    }

    public function test_notification_page_shows_the_not_sent_reason(): void
    {
        config(['services.africastalking.api_key' => null]);
        (new SmsService())->send('+254722000001', 'Hello Alice, routine wellness review notice.', 'custom', $this->patient);

        $this->actingAs($this->clinician)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('SMS is not set up.')
            ->assertSee('no Africa', false);
    }
}