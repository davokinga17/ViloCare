<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\VlDueService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dashboard "Total Due for VL Today" counts patients whose due date is today (not appointments),
 * and its link opens a due list with exactly the same patients.
 */
class DueTodayCardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Facility $kapoeta;

    private Facility $torit;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $this->kapoeta = Facility::where('code', 'KCH-001')->firstOrFail();
        $this->torit = Facility::where('code', 'TSH-009')->firstOrFail();

        $this->admin = User::create([
            'name' => 'Admin Boss',
            'email' => 'admin@clinic.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function patient(?Facility $facility = null, array $attributes = []): Patient
    {
        $facility ??= $this->kapoeta;
        $this->seq++;

        return Patient::create($attributes + [
            'art_number' => 'ART-TODAY-'.$this->seq,
            'first_name' => 'Test',
            'last_name' => 'Patient'.$this->seq,
            'sex' => 'male',
            'age' => 34,
            'art_start_date' => '2022-03-01',
            'facility_id' => $facility->id,
            'county_id' => $facility->county_id,
            'state_id' => $facility->county->state_id,
            'status' => 'active',
        ]);
    }

    private function vlResult(Patient $patient, int $dueOffsetDays): void
    {
        ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => today()->subDays(150)->toDateString(),
            'result_date' => today()->subDays(150)->toDateString(),
            'result_value' => 50,
            'is_suppressed' => true,
            'lab_name' => 'Kapoeta Civil Hospital',
            'sample_type' => 'Plasma',
            'next_due_date' => today()->addDays($dueOffsetDays)->toDateString(),
            'algorithm_reason' => 'Test reason',
        ]);
    }

    private function pmtctDueToday(): Patient
    {
        return $this->patient(null, [
            'sex' => 'female',
            'is_pregnant' => true,
            'pregnancy_onset_at' => today()->subDays(30)->toDateString(),
        ]);
    }

    private function cardCount(?Facility $facility = null): int
    {
        $query = ['facility_id' => ($facility ?? $this->kapoeta)->id];

        return $this->actingAs($this->admin)->get(route('dashboard', $query))->viewData('metrics')['due_today'];
    }

    public function test_routine_patient_due_today_shows_one_and_matches_the_list(): void
    {
        $due = $this->patient();
        $this->vlResult($due, 0);

        $this->assertSame(1, $this->cardCount());

        $list = $this->actingAs($this->admin)->get(route('reports.due-list', [
            'facility_id' => $this->kapoeta->id, 'period' => 'today',
        ]));
        $list->assertOk()->assertSee('Total Due: 1 Patients')->assertSee($due->art_number);
    }

    public function test_dashboard_card_links_to_the_list_with_the_same_filters(): void
    {
        $this->vlResult($this->patient(), 0);

        // No filter params: the dashboard defaults to Kapoeta Civil Hospital and its state/county.
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->getContent();

        $href = route('reports.due-list', [
            'state_id' => $this->kapoeta->county->state_id,
            'county_id' => $this->kapoeta->county_id,
            'facility_id' => $this->kapoeta->id,
            'period' => 'today',
        ]);
        $this->assertStringContainsString(e($href), $html);
    }

    public function test_overdue_patient_is_not_due_today_but_is_in_cumulative(): void
    {
        $this->vlResult($this->patient(), -10);

        $dashboard = $this->actingAs($this->admin)->get(route('dashboard', ['facility_id' => $this->kapoeta->id]));
        $this->assertSame(0, $dashboard->viewData('metrics')['due_today']);
        $this->assertSame(1, $dashboard->viewData('metrics')['due_for_vl']);

        $this->actingAs($this->admin)
            ->get(route('reports.due-list', ['facility_id' => $this->kapoeta->id, 'period' => 'today']))
            ->assertSee('Total Due: 0 Patients');
    }

    public function test_future_patient_is_not_due_today(): void
    {
        $this->vlResult($this->patient(), 3);

        $this->assertSame(0, $this->cardCount());
    }

    public function test_immediate_pmtct_patient_counts_as_due_today_once(): void
    {
        $pregnant = $this->pmtctDueToday();
        // Her last VL predates the pregnancy and is also routine-overdue: still a single "today" row.
        $this->vlResult($pregnant, -20);

        $this->assertSame(1, $this->cardCount());

        $this->actingAs($this->admin)
            ->get(route('reports.due-list', ['facility_id' => $this->kapoeta->id, 'period' => 'today']))
            ->assertSee('Total Due: 1 Patients')
            ->assertSee($pregnant->art_number);
    }

    public function test_appointments_do_not_change_the_card_but_still_feed_appointments_today(): void
    {
        $patient = $this->patient();
        foreach ([1, 2] as $i) {
            Appointment::create([
                'patient_id' => $patient->id,
                'appointment_date' => today()->toDateString(),
                'reason' => 'Routine Clinical Review',
                'status' => 'scheduled',
                'scheduled_by' => $this->admin->id,
            ]);
        }

        $metrics = $this->actingAs($this->admin)->get(route('dashboard', ['facility_id' => $this->kapoeta->id]))->viewData('metrics');

        $this->assertSame(0, $metrics['due_today'], 'appointments are not VL due dates');
        $this->assertSame(2, $metrics['today_appointments']);
    }

    public function test_card_respects_the_location_filter(): void
    {
        $this->vlResult($this->patient($this->torit), 0);

        $this->assertSame(0, $this->cardCount($this->kapoeta));
        $this->assertSame(1, $this->cardCount($this->torit));
    }

    public function test_card_list_and_service_always_agree(): void
    {
        $this->vlResult($this->patient(), 0);
        $this->vlResult($this->patient(), 0);
        $this->vlResult($this->patient(), -5);
        $this->vlResult($this->patient(), 4);
        $this->vlResult($this->pmtctDueToday(), 90);

        $location = ['facility_id' => $this->kapoeta->id];
        $service = app(VlDueService::class)->dueToday($location);

        $this->assertCount(3, $service);
        $this->assertSame(3, $this->cardCount());

        $list = $this->actingAs($this->admin)->get(route('reports.due-list', $location + ['period' => 'today']));
        $list->assertSee('Total Due: 3 Patients');
        foreach ($service as $item) {
            $list->assertSee($item->patient->art_number);
        }
    }
}

