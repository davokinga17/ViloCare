<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\TestingLaboratory;
use App\Models\User;
use App\Models\ViralLoadResult;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ViralLoadTestingLabTest extends TestCase
{
    use RefreshDatabase;

    private const LABS = [
        'Kapoeta Civil Hospital',
        'Magwi PHCC',
        'Narus PHCC',
        'National Public Health Laboratory',
        'Nimule Hospital',
        'St. Theresa Mission Hospital Isohe',
        'Torit State Hospital',
    ];

    protected User $labTech;
    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $facility = Facility::where('name', 'Kapoeta Civil Hospital')->firstOrFail();

        $this->labTech = User::create([
            'name' => 'Lab Brian',
            'email' => 'brian@clinic.org',
            'role' => UserRole::LAB_TECHNICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->patient = Patient::create([
            'art_number' => 'ART-70001',
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'sex' => 'male',
            'age' => 34,
            'art_start_date' => '2022-03-01',
            'facility_id' => $facility->id,
            'county_id' => $facility->county_id,
            'state_id' => $facility->county->state_id,
            'status' => 'active',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 60,
            'sample_type' => 'Plasma',
            'lab_name' => 'Torit State Hospital',
        ], $overrides);
    }

    public function test_exactly_seven_active_testing_laboratories_exist(): void
    {
        $this->assertCount(7, self::LABS);
        $this->assertEquals(self::LABS, TestingLaboratory::active()->pluck('name')->all());
    }

    public function test_national_public_health_laboratory_is_accepted(): void
    {
        $this->actingAs($this->labTech)
            ->post('/viral-load', $this->payload(['lab_name' => 'National Public Health Laboratory']))
            ->assertSessionHasNoErrors();

        $this->assertEquals('National Public Health Laboratory', ViralLoadResult::first()->lab_name);
    }

    public function test_inactive_laboratory_is_hidden_and_rejected_but_old_results_keep_its_name(): void
    {
        $old = ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2025-01-10',
            'result_date' => '2025-01-12',
            'result_value' => 60,
            'is_suppressed' => true,
            'lab_name' => 'Magwi PHCC',
            'sample_type' => 'Plasma',
            'recorded_by' => $this->labTech->id,
        ]);
        TestingLaboratory::where('name', 'Magwi PHCC')->update(['is_active' => false]);

        preg_match('/<select id="lab_name".*?<\/select>/s', $this->actingAs($this->labTech)->get('/viral-load/create')->getContent(), $m);
        $this->assertStringNotContainsString('Magwi PHCC', $m[0]);

        $this->actingAs($this->labTech)->post('/viral-load', $this->payload(['lab_name' => 'Magwi PHCC']))
            ->assertSessionHasErrors('lab_name');
        $this->actingAs($this->labTech)->get(route('viral-load.show', $old))->assertOk()->assertSee('Magwi PHCC');
    }

    public function test_create_form_lists_exactly_the_seven_labs(): void
    {
        $response = $this->actingAs($this->labTech)->get('/viral-load/create');

        $response->assertOk();
        $response->assertSee('-- Select testing laboratory --', false);
        $response->assertDontSee('National HIV Reference Lab');

        preg_match('/<select id="lab_name".*?<\/select>/s', $response->getContent(), $m);
        preg_match_all('/<option value="([^"]+)"/', $m[0], $opts);
        $this->assertEquals(self::LABS, $opts[1]);
    }

    public function test_valid_lab_is_accepted(): void
    {
        $this->actingAs($this->labTech)->post('/viral-load', $this->payload())->assertSessionHasNoErrors();

        $this->assertEquals('Torit State Hospital', ViralLoadResult::first()->lab_name);
    }

    public function test_non_lab_facility_is_rejected(): void
    {
        $this->actingAs($this->labTech)
            ->post('/viral-load', $this->payload(['lab_name' => 'Pageri PHCC']))
            ->assertSessionHasErrors('lab_name');

        $this->assertEquals(0, ViralLoadResult::count());
    }

    public function test_arbitrary_string_is_rejected(): void
    {
        $this->actingAs($this->labTech)
            ->post('/viral-load', $this->payload(['lab_name' => 'National HIV Reference Lab']))
            ->assertSessionHasErrors('lab_name');
    }

    public function test_missing_lab_is_rejected(): void
    {
        $this->actingAs($this->labTech)
            ->post('/viral-load', $this->payload(['lab_name' => '']))
            ->assertSessionHasErrors('lab_name');
    }

    public function test_pages_with_legacy_lab_value_still_render(): void
    {
        $result = ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 60,
            'is_suppressed' => true,
            'lab_name' => 'National HIV Reference Lab',
            'sample_type' => 'Plasma',
            'recorded_by' => $this->labTech->id,
        ]);

        $this->actingAs($this->labTech)->get(route('viral-load.show', $result))
            ->assertOk()->assertSee('National HIV Reference Lab');
        $this->actingAs($this->labTech)->get('/viral-load')
            ->assertOk()->assertSee('National HIV Reference Lab');
    }
}
