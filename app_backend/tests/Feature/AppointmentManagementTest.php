<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AppointmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $clinician;
    protected User $dataClerk;
    protected User $labTech;
    protected Facility $facility;
    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $this->facility = Facility::firstOrFail();

        $this->admin = User::create([
            'name' => 'Admin Super',
            'email' => 'admin@art.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->clinician = User::create([
            'name' => 'Dr. Robert',
            'email' => 'robert@art.org',
            'role' => UserRole::CLINICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->dataClerk = User::create([
            'name' => 'Clerk Joy',
            'email' => 'joy@art.org',
            'role' => UserRole::DATA_CLERK,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->labTech = User::create([
            'name' => 'Tech Simon',
            'email' => 'simon@art.org',
            'role' => UserRole::LAB_TECHNICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->patient = Patient::create([
            'art_number' => 'ART-80001',
            'first_name' => 'David',
            'last_name' => 'Otieno',
            'sex' => 'male',
            'age' => 38,
            'art_start_date' => '2022-06-01',
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
        ]);
    }

    public function test_data_clerk_can_schedule_appointment(): void
    {
        $response = $this->actingAs($this->dataClerk)->post('/appointments', [
            'patient_id' => $this->patient->id,
            'appointment_date' => '2026-08-20',
            'appointment_time' => 'Morning (08:00 AM - 12:00 PM)',
            'reason' => 'Viral Load Sample Collection',
            'notes' => '6-month routine viral load bleed scheduled.',
        ]);

        $appointment = Appointment::where('patient_id', $this->patient->id)->first();
        $this->assertNotNull($appointment);
        $this->assertEquals('2026-08-20', $appointment->appointment_date->toDateString());
        $this->assertEquals('Viral Load Sample Collection', $appointment->reason);
        $this->assertEquals('scheduled', $appointment->status);
        $this->assertEquals($this->dataClerk->id, $appointment->scheduled_by);
        $this->assertEquals('pending', $appointment->sync_status);

        $response->assertRedirect(route('appointments.index'));
    }

    public function test_staff_can_mark_appointment_completed_with_attendance_timestamp(): void
    {
        $appointment = Appointment::create([
            'patient_id' => $this->patient->id,
            'appointment_date' => now()->toDateString(),
            'reason' => 'Routine Clinical Review',
            'status' => 'scheduled',
            'scheduled_by' => $this->dataClerk->id,
        ]);

        $response = $this->actingAs($this->clinician)->post(route('appointments.status', $appointment), [
            'status' => 'completed',
            'notes' => 'Patient seen. Adherence verified good >95%.',
        ]);

        $appointment->refresh();
        $this->assertEquals('completed', $appointment->status);
        $this->assertTrue($appointment->isCompleted());
        $this->assertNotNull($appointment->attended_at);
        $this->assertEquals($this->clinician->id, $appointment->attended_by);
        $this->assertStringContainsString('Adherence verified', $appointment->notes);
    }

    public function test_staff_can_mark_appointment_missed(): void
    {
        $appointment = Appointment::create([
            'patient_id' => $this->patient->id,
            'appointment_date' => now()->subDay()->toDateString(),
            'reason' => 'ARV Drug Refill',
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->dataClerk)->post(route('appointments.status', $appointment), [
            'status' => 'missed',
            'notes' => 'Patient did not show. Phone call queued for community tracking.',
        ]);

        $appointment->refresh();
        $this->assertEquals('missed', $appointment->status);
        $this->assertTrue($appointment->isMissed());
    }

    public function test_staff_can_reschedule_appointment_preserving_history(): void
    {
        $initialAppointment = Appointment::create([
            'patient_id' => $this->patient->id,
            'appointment_date' => now()->toDateString(),
            'reason' => 'Routine Clinical Review',
            'status' => 'scheduled',
        ]);

        $newDate = now()->addDays(14)->toDateString();

        $response = $this->actingAs($this->clinician)->post(route('appointments.reschedule', $initialAppointment), [
            'new_date' => $newDate,
            'new_time' => 'Morning (08:00 AM - 12:00 PM)',
            'reschedule_reason' => 'Patient travelling for work',
        ]);

        $initialAppointment->refresh();
        $this->assertEquals('rescheduled', $initialAppointment->status);
        $this->assertStringContainsString('Rescheduled to', $initialAppointment->notes);

        // Verify new scheduled appointment exists
        $newAppointment = Appointment::where('patient_id', $this->patient->id)
            ->where('status', 'scheduled')
            ->whereDate('appointment_date', $newDate)
            ->first();

        $this->assertNotNull($newAppointment);
        $this->assertEquals('Routine Clinical Review', $newAppointment->reason);
        $this->assertEquals('pending', $newAppointment->sync_status);

        $response->assertRedirect(route('appointments.index'));
    }

    public function test_lab_technician_cannot_access_appointment_management(): void
    {
        $response = $this->actingAs($this->labTech)->get('/appointments');
        $response->assertStatus(403);

        $createResponse = $this->actingAs($this->labTech)->get('/appointments/create');
        $createResponse->assertStatus(403);
    }
}
