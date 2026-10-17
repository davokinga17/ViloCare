<?php

namespace Tests\Feature;

use App\Models\County;
use App\Models\Facility;
use App\Models\State;
use App\Support\ClinicDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function facilities(): void
    {
        Facility::query()->delete();
        $state = State::create(['name' => 'Test State', 'code' => 'TST']);
        $county = County::create(['name' => 'Test County', 'code' => 'TSC', 'state_id' => $state->id]);
        Facility::create(['name' => 'Alpha Clinic', 'code' => 'AAA-001', 'county_id' => $county->id]);
        Facility::create(['name' => 'Beta Clinic', 'code' => 'BBB-002', 'county_id' => $county->id]);
    }

    public function test_configured_facility_code_picks_that_facility(): void
    {
        $this->facilities();
        config(['vilocare.default_facility_code' => 'BBB-002']);

        $this->assertSame('Beta Clinic', ClinicDefaults::facility()->name);
        $this->assertSame('Beta Clinic', ClinicDefaults::facility(Facility::orderBy('name')->get())->name);
    }

    public function test_empty_or_unknown_code_falls_back_to_the_first_facility(): void
    {
        $this->facilities();

        config(['vilocare.default_facility_code' => '']);
        $this->assertSame('Alpha Clinic', ClinicDefaults::facility()->name);

        config(['vilocare.default_facility_code' => 'NOPE']);
        $this->assertSame('Alpha Clinic', ClinicDefaults::facility(Facility::orderBy('name')->get())->name);
    }

    public function test_clinic_name_uses_config_then_the_default_facility(): void
    {
        $this->facilities();
        config(['vilocare.default_facility_code' => 'BBB-002', 'vilocare.clinic_name' => null]);
        $this->assertSame('Beta Clinic', ClinicDefaults::name());

        config(['vilocare.clinic_name' => 'Owner Health Centre']);
        $this->assertSame('Owner Health Centre', ClinicDefaults::name());
    }

    public function test_dashboard_and_verification_page_follow_the_configuration(): void
    {
        $this->facilities();
        config(['vilocare.default_facility_code' => 'BBB-002', 'vilocare.clinic_name' => 'Owner Health Centre']);
        $admin = \App\Models\User::create([
            'name' => 'Admin Test', 'email' => 'cfg.admin@example.org', 'phone' => '+211900000000',
            'role' => \App\Enums\UserRole::ADMINISTRATOR, 'password' => 'a-long-test-value-1', 'is_active' => true,
        ]);

        $this->actingAs($admin)->get('/reports/verify?ref=VLC-DUE-20260101-ABC123')
            ->assertOk()->assertSee('Owner Health Centre')->assertDontSee('Kapoeta');

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }
}
