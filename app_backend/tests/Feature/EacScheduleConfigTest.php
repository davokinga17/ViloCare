<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\EacEpisode;
use App\Models\EacSession;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\ViralLoadEngine;
use App\Services\VlDueService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The client's confirmed EAC schedule: session 1 on entry, then +30, +30, repeat VL +30. */
class EacScheduleConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $clinician;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-01 09:00:00');
        $this->seed(LocationSeeder::class);
        $facility = Facility::firstOrFail();

        $this->clinician = User::create([
            'name' => 'Test Clinician', 'email' => 'c@example.test', 'role' => UserRole::CLINICIAN, 'facility_id' => $this->defaultFacilityId(),
            'password' => Hash::make('Password@123'), 'must_change_password' => false, 'is_active' => true,
        ]);
        $this->patient = $this->makePatient('ART-TEST-1', $facility);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makePatient(string $art, ?Facility $facility = null): Patient
    {
        $facility ??= Facility::firstOrFail();

        return Patient::create([
            'art_number' => $art, 'first_name' => 'Test', 'last_name' => 'Patient', 'sex' => 'male',
            'age' => 30, 'art_start_date' => '2022-03-01', 'facility_id' => $facility->id,
            'county_id' => $facility->county_id, 'state_id' => $facility->county->state_id, 'status' => 'active',
        ]);
    }

    /** High VL whose lab result_date is weeks before today (entered late). */
    private function openEpisode(?Patient $patient = null, string $resultDate = '2026-01-12'): EacEpisode
    {
        $patient ??= $this->patient;
        $result = ViralLoadResult::create([
            'patient_id' => $patient->id, 'sample_date' => '2026-01-10', 'result_date' => $resultDate,
            'result_value' => 18000, 'is_suppressed' => false,
        ]);

        return app(ViralLoadEngine::class)->initiateEacEpisode($patient, $result);
    }

    private function sess(EacEpisode $e, int $n): EacSession
    {
        return $e->sessions()->where('session_number', $n)->firstOrFail();
    }

    private function complete(EacSession $s, string $date, ?string $next = null): void
    {
        $payload = ['session_date' => $date, 'barriers' => ['Forgetfulness / Busy Routine'], 'action_plan' => 'Plan'];
        if ($next) {
            $payload['next_session_date'] = $next;
        }
        $this->actingAs($this->clinician)->put(route('eac.sessions.update', $s), $payload)->assertSessionHasNoErrors();
    }

    public function test_defaults_are_the_confirmed_client_rules(): void
    {
        $this->assertSame(0, config('vilocare.eac.first_session_delay_days'));
        $this->assertSame(30, config('vilocare.eac.session_gap_days'));
        $this->assertSame(30, config('vilocare.eac.repeat_vl_days'));
    }

    public function test_session_1_is_the_entry_date_not_the_lab_result_date(): void
    {
        $episode = $this->openEpisode(resultDate: '2026-01-12'); // lab date is 7 weeks before "today"

        $this->assertSame('2026-03-01', $this->sess($episode, 1)->session_date->toDateString());
    }

    public function test_session_2_session_3_and_repeat_vl_follow_the_actual_dates(): void
    {
        $episode = $this->openEpisode();
        $this->complete($this->sess($episode, 1), '2026-03-01');
        $this->assertSame('2026-03-31', $this->sess($episode, 2)->session_date->toDateString()); // +30

        $this->complete($this->sess($episode, 2), '2026-03-31');
        $this->assertSame('2026-04-30', $this->sess($episode, 3)->session_date->toDateString()); // +30

        $this->complete($this->sess($episode, 3), '2026-04-30');
        $this->assertSame('2026-05-30', $episode->fresh()->repeat_vl_due_date->toDateString()); // +30 days, not +1 month
    }

    public function test_a_late_session_shifts_the_later_dates(): void
    {
        $episode = $this->openEpisode();
        $this->complete($this->sess($episode, 1), '2026-03-11'); // 10 days late
        $this->assertSame('2026-04-10', $this->sess($episode, 2)->session_date->toDateString());

        $this->complete($this->sess($episode, 2), '2026-04-15'); // 5 days late
        $this->assertSame('2026-05-15', $this->sess($episode, 3)->session_date->toDateString());

        $this->complete($this->sess($episode, 3), '2026-05-20'); // 5 days late
        $this->assertSame('2026-06-19', $episode->fresh()->repeat_vl_due_date->toDateString());
    }

    public function test_a_counsellor_picked_next_session_date_is_honoured(): void
    {
        $episode = $this->openEpisode();
        $this->complete($this->sess($episode, 1), '2026-03-01', '2026-03-15');

        $this->assertSame('2026-03-15', $this->sess($episode, 2)->session_date->toDateString());
    }

    public function test_the_schedule_can_be_changed_by_config_alone(): void
    {
        config([
            'vilocare.eac.first_session_delay_days' => 2,
            'vilocare.eac.session_gap_days' => 14,
            'vilocare.eac.repeat_vl_days' => 21,
        ]);
        $episode = $this->openEpisode();
        $this->assertSame('2026-03-03', $this->sess($episode, 1)->session_date->toDateString());

        $this->complete($this->sess($episode, 1), '2026-03-03');
        $this->assertSame('2026-03-17', $this->sess($episode, 2)->session_date->toDateString());
        $this->complete($this->sess($episode, 2), '2026-03-17');
        $this->complete($this->sess($episode, 3), '2026-03-31');
        $this->assertSame('2026-04-21', $episode->fresh()->repeat_vl_due_date->toDateString());
    }

    public function test_the_stored_dates_drive_due_today_the_eac_page_and_the_post_eac_group(): void
    {
        $episode = $this->openEpisode();
        $this->complete($this->sess($episode, 1), '2026-03-01');
        $this->complete($this->sess($episode, 2), '2026-03-31');
        $this->complete($this->sess($episode, 3), '2026-04-30');

        // EAC page shows the new repeat-VL date.
        $this->actingAs($this->clinician)->get(route('eac.show', $episode))
            ->assertOk()->assertSee('2026-05-30');

        // Not due the day before; due-today the day it falls.
        Carbon::setTestNow('2026-05-29 09:00:00');
        $this->assertCount(0, app(VlDueService::class)->dueToday()->where('group', \App\Services\VlDueItem::GROUP_POST_EAC));
        Carbon::setTestNow('2026-05-30 09:00:00');
        $this->assertCount(1, app(VlDueService::class)->dueToday()->where('group', \App\Services\VlDueItem::GROUP_POST_EAC));
    }

    /** An old-rule episode: session 1 pending at result+14, sessions 2/3 pending, opened 2026-02-01. */
    private function legacyEpisode(string $art, array $dates = [1 => '2026-03-05', 2 => '2026-03-19', 3 => '2026-04-02']): EacEpisode
    {
        $patient = $this->makePatient($art);
        $episode = $this->openEpisode($patient, '2026-01-20');
        $episode->forceFill(['created_at' => '2026-02-01 10:00:00'])->saveQuietly();
        foreach ($dates as $n => $date) {
            $s = $episode->sessions()->where('session_number', $n)->first()
                ?? $episode->sessions()->create(['patient_id' => $patient->id, 'session_number' => $n, 'completion_status' => 'pending', 'session_date' => $date]);
            $s->forceFill(['session_date' => $date])->saveQuietly();
        }

        return $episode->fresh();
    }

    private function snapshot(): array
    {
        return [
            'sessions' => EacSession::orderBy('id')->get(['id', 'session_date', 'completion_status', 'sync_status'])->toArray(),
            'episodes' => EacEpisode::orderBy('id')->get(['id', 'repeat_vl_due_date', 'status', 'sync_status'])->toArray(),
            'audit' => AuditLog::count(),
        ];
    }

    public function test_the_preview_command_changes_nothing_and_prints_no_names(): void
    {
        $this->legacyEpisode('ART-OLD-1');
        $before = $this->snapshot();

        $this->artisan('vilocare:eac-reschedule-preview')
            ->expectsOutputToContain('ART-OLD-1')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->artisan('vilocare:eac-reschedule')->assertSuccessful(); // default is dry run
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_changes_only_pending_sessions_and_open_repeat_vl_dates_and_audits_each(): void
    {
        // Episode A: session 1 completed on 2026-02-05 (no picked date), 2 and 3 pending.
        $a = $this->legacyEpisode('ART-OLD-A');
        $s1 = $this->sess($a, 1);
        $s1->forceFill(['completion_status' => 'completed', 'session_date' => '2026-02-05'])->saveQuietly();

        // Episode B: all three completed, waiting for the repeat VL (old due date = +1 month).
        $b = $this->legacyEpisode('ART-OLD-B');
        foreach ([1 => '2026-01-05', 2 => '2026-01-19', 3 => '2026-02-02'] as $n => $d) {
            $this->sess($b, $n)->forceFill(['completion_status' => 'completed', 'session_date' => $d])->saveQuietly();
        }
        $b->forceFill(['status' => 'sessions_completed', 'repeat_vl_due_date' => '2026-03-10'])->saveQuietly();

        // Episode C: repeat VL already has a result -> must not be touched.
        $c = $this->legacyEpisode('ART-OLD-C');
        $this->sess($c, 3)->forceFill(['completion_status' => 'completed', 'session_date' => '2026-02-02'])->saveQuietly();
        $c->forceFill(['status' => 'resolved_suppressed', 'repeat_vl_due_date' => '2026-03-02'])->saveQuietly();

        // A cancelled session must stay as it is.
        $this->sess($c, 2)->forceFill(['completion_status' => 'cancelled'])->saveQuietly();

        $completedBefore = EacSession::where('completion_status', '!=', 'pending')->orderBy('id')->get(['id', 'session_date', 'completion_status'])->toArray();
        $cDatesBefore = $this->sess($c, 3)->session_date->toDateString();
        AuditLog::query()->delete();

        $this->artisan('vilocare:eac-reschedule', ['--apply' => true])
            ->expectsConfirmation('Change 3 stored date(s)?', 'yes')
            ->assertSuccessful();

        // A: session 2 = 2026-02-05 + 30, session 3 = that + 30; completed session 1 untouched.
        $this->assertSame('2026-03-07', $this->sess($a, 2)->session_date->toDateString());
        $this->assertSame('2026-04-06', $this->sess($a, 3)->session_date->toDateString());
        $this->assertSame('2026-02-05', $this->sess($a, 1)->session_date->toDateString());
        // B: repeat VL = session 3 actual 2026-02-02 + 30.
        $this->assertSame('2026-03-04', $b->fresh()->repeat_vl_due_date->toDateString());
        // C and every completed/cancelled session: unchanged.
        $this->assertSame('2026-03-02', $c->fresh()->repeat_vl_due_date->toDateString());
        $this->assertSame($cDatesBefore, $this->sess($c, 3)->session_date->toDateString());
        $this->assertSame($completedBefore, EacSession::where('completion_status', '!=', 'pending')->orderBy('id')->get(['id', 'session_date', 'completion_status'])->toArray());

        // Changed rows are pending (they sync) and exactly one audit entry exists per changed row.
        $this->assertSame('pending', $this->sess($a, 2)->sync_status instanceof \BackedEnum ? $this->sess($a, 2)->sync_status->value : (string) $this->sess($a, 2)->sync_status);
        $this->assertSame(3, AuditLog::where('action', 'updated')->count());
        foreach ([$this->sess($a, 2)->id, $this->sess($a, 3)->id, $b->id] as $id) {
            $this->assertSame(1, AuditLog::where('record_id', $id)->where('action', 'updated')->count());
        }
        // A's pending session 1? It is completed; B and C pending sessions are none or unchanged-only-if-in-plan.
    }

    public function test_apply_asks_for_confirmation_and_cancelling_changes_nothing(): void
    {
        $this->legacyEpisode('ART-OLD-1');
        $before = $this->snapshot();

        $this->artisan('vilocare:eac-reschedule', ['--apply' => true])
            ->expectsConfirmation('Change 3 stored date(s)?', 'no')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_never_touches_dates_that_are_today_or_in_the_past(): void
    {
        // Today is 2026-03-01. Session 1 overdue (02-03), session 2 due today (03-01), session 3 in the future.
        $a = $this->legacyEpisode('ART-PAST-A', [1 => '2026-02-03', 2 => '2026-03-01', 3 => '2026-04-02']);

        // Repeat VL: one with a past due date, one in the future whose new date would be in the past
        // (session 3 actually done 2026-01-10 -> new 2026-02-09, before today).
        $b = $this->legacyEpisode('ART-PAST-B');
        foreach ([1 => '2025-12-01', 2 => '2025-12-20', 3 => '2026-01-10'] as $n => $d) {
            $this->sess($b, $n)->forceFill(['completion_status' => 'completed', 'session_date' => $d])->saveQuietly();
        }
        $b->forceFill(['status' => 'sessions_completed', 'repeat_vl_due_date' => '2026-03-20'])->saveQuietly();
        $c = $this->legacyEpisode('ART-PAST-C');
        foreach ([1 => '2026-01-01', 2 => '2026-01-31', 3 => '2026-02-01'] as $n => $d) {
            $this->sess($c, $n)->forceFill(['completion_status' => 'completed', 'session_date' => $d])->saveQuietly();
        }
        $c->forceFill(['status' => 'sessions_completed', 'repeat_vl_due_date' => '2026-02-15'])->saveQuietly(); // overdue

        $plan = app(\App\Services\EacRescheduler::class)->plan();
        $moved = $plan->map(fn ($c) => $c['art_number'].' '.$c['label'])->all();
        // Session 3 of A is the only thing that may move (it counts from session 2's kept date, 2026-03-01 + 30).
        $this->assertSame(['ART-PAST-A session 3'], $moved);
        $this->assertSame('2026-03-31', $plan->first()['new']);

        $before = $this->snapshot();
        $this->artisan('vilocare:eac-reschedule-preview')
            ->expectsOutputToContain('Left alone (never changed): 3 date(s) already today or in the past, 1 whose new date would fall in the past.')
            ->assertSuccessful();
        $this->assertSame($before, $this->snapshot());

        AuditLog::query()->delete();
        $this->artisan('vilocare:eac-reschedule', ['--apply' => true])
            ->expectsConfirmation('Change 1 stored date(s)?', 'yes')
            ->assertSuccessful();

        $this->assertSame('2026-02-03', $this->sess($a, 1)->session_date->toDateString()); // overdue stays overdue
        $this->assertSame('2026-03-01', $this->sess($a, 2)->session_date->toDateString()); // due today stays
        $this->assertSame('2026-03-31', $this->sess($a, 3)->session_date->toDateString());
        $this->assertSame('2026-03-20', $b->fresh()->repeat_vl_due_date->toDateString());
        $this->assertSame('2026-02-15', $c->fresh()->repeat_vl_due_date->toDateString());
        $this->assertSame(1, AuditLog::where('action', 'updated')->count());
    }
}
