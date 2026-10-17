<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\SyncService;
use App\Services\VlDueService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * How a new VL result affects an open EAC episode (status active or sessions_completed).
 */
class EacOpenEpisodeRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $labTech;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        $facility = Facility::where('code', 'KCH-001')->firstOrFail();

        $this->labTech = User::create([
            'name' => 'Lab Brian', 'email' => 'brian@clinic.org', 'role' => UserRole::LAB_TECHNICIAN,
            'password' => Hash::make('Password@123'), 'must_change_password' => false, 'is_active' => true,
        ]);
        $this->patient = Patient::create([
            'art_number' => 'ART-RULE-1', 'first_name' => 'Rule', 'last_name' => 'One', 'sex' => 'female', 'age' => 30,
            'art_start_date' => '2022-03-01', 'facility_id' => $facility->id, 'county_id' => $facility->county_id,
            'state_id' => $facility->county->state_id, 'status' => 'active',
        ]);
    }

    private function enter(int $value, array $extra = [])
    {
        return $this->actingAs($this->labTech)->post('/viral-load', array_merge([
            'patient_id' => $this->patient->id, 'sample_date' => '2026-06-01', 'result_date' => '2026-06-02',
            'result_value' => $value, 'sample_type' => 'Plasma', 'lab_name' => 'Kapoeta Civil Hospital',
        ], $extra));
    }

    /** Opens an episode through the engine, then marks it and its sessions as already synced. */
    private function openEpisode(int $completed = 0): EacEpisode
    {
        $this->enter(5000, ['sample_date' => '2026-01-01', 'result_date' => '2026-01-02'])->assertRedirect();
        $episode = EacEpisode::where('patient_id', $this->patient->id)->firstOrFail();

        // Sessions 1..3 exist; the first $completed are done, the rest pending.
        $episode->sessions()->delete();
        for ($n = 1; $n <= 3; $n++) {
            $episode->sessions()->create([
                'patient_id' => $this->patient->id, 'session_number' => $n, 'session_date' => "2026-02-0{$n}",
                'completion_status' => $n <= $completed ? 'completed' : 'pending',
            ]);
        }
        if ($completed >= 3) {
            $episode->update(['status' => 'sessions_completed', 'repeat_vl_due_date' => '2026-05-01']);
        }

        DB::table('eac_episodes')->where('id', $episode->id)->update(['sync_status' => 'synced', 'last_modified_at' => '2026-01-05 08:00:00']);
        DB::table('eac_sessions')->where('eac_episode_id', $episode->id)->update(['sync_status' => 'synced', 'last_modified_at' => '2026-01-05 08:00:00']);

        return $episode->fresh();
    }

    private function latestResult(): ViralLoadResult
    {
        return ViralLoadResult::where('patient_id', $this->patient->id)->orderByDesc('created_at')->orderByDesc('sample_date')->firstOrFail();
    }

    public function test_suppressed_before_session_3_closes_the_episode_early(): void
    {
        $episode = $this->openEpisode(completed: 1);

        $response = $this->enter(40);
        $response->assertSessionHas('success', fn ($m) => str_contains($m, 'EAC closed early, pending sessions cancelled'));

        $result = $this->latestResult();
        $episode->refresh();
        $this->assertSame('resolved_suppressed', $episode->status);
        $this->assertSame($result->id, $episode->repeat_viral_load_result_id);
        $this->assertSame($episode->id, $result->eac_episode_id);
        $this->assertFalse($result->is_post_eac_repeat, 'EAC was not completed, so not a post-EAC repeat');
        $this->assertSame('2026-12-01', $result->next_due_date->toDateString(), 'routine 6-month schedule');
        $this->assertTrue($episode->wasClosedEarly());

        $statuses = $episode->sessions->pluck('completion_status', 'session_number')->all();
        $this->assertSame([1 => 'completed', 2 => 'cancelled', 3 => 'cancelled'], $statuses);
        $this->assertTrue(AuditLog::where('action', 'eac_closed_early')->where('record_id', $episode->id)->exists());
    }

    public function test_closing_marks_episode_and_cancelled_sessions_pending_with_new_last_modified(): void
    {
        $episode = $this->openEpisode(completed: 0);

        $this->enter(40)->assertRedirect();

        $episode->refresh();
        $this->assertSame('pending', $episode->sync_status);
        $this->assertTrue($episode->last_modified_at->gt('2026-01-05 08:00:00'));
        foreach ($episode->sessions as $session) {
            $this->assertSame('cancelled', $session->completion_status);
            $this->assertSame('pending', $session->sync_status);
            $this->assertTrue($session->last_modified_at->gt('2026-01-05 08:00:00'));
        }

        // Both go out on the next push.
        $payload = app(SyncService::class)->getPendingLocalPayload()['tables'];
        $this->assertContains($episode->id, array_column($payload['eac_episodes'], 'id'));
        $this->assertEqualsCanonicalizing($episode->sessions->pluck('id')->all(), array_column($payload['eac_sessions'], 'id'));
    }

    public function test_the_closure_reaches_a_clinic_that_still_has_the_open_copy(): void
    {
        $episode = $this->openEpisode(completed: 0);
        $cloudEpisode = null;
        $this->enter(40)->assertRedirect();
        $cloudEpisode = $episode->fresh()->getAttributes();
        $cloudSessions = $episode->sessions()->get()->map->getAttributes()->all();

        // The receiving side still has the open copy with the old timestamps.
        DB::table('eac_episodes')->where('id', $episode->id)->update(['status' => 'active', 'repeat_viral_load_result_id' => null, 'last_modified_at' => '2026-01-05 08:00:00']);
        DB::table('eac_sessions')->where('eac_episode_id', $episode->id)->update(['completion_status' => 'pending', 'last_modified_at' => '2026-01-05 08:00:00']);

        $applied = app(SyncService::class)->applyRemotePull(['eac_episodes' => [$cloudEpisode], 'eac_sessions' => $cloudSessions]);

        $this->assertSame(4, $applied['applied']);
        $this->assertSame('resolved_suppressed', $episode->fresh()->status);
        $this->assertSame(['cancelled'], $episode->sessions()->pluck('completion_status')->unique()->all());
    }

    public function test_high_before_session_3_keeps_the_episode_open_and_records_an_ordinary_result(): void
    {
        $episode = $this->openEpisode(completed: 1);
        $sessionsBefore = DB::table('eac_sessions')->orderBy('id')->get()->toJson();
        $episodeBefore = DB::table('eac_episodes')->orderBy('id')->get()->toJson();

        $response = $this->enter(9000);
        $response->assertSessionHas('warning', fn ($m) => str_contains($m, 'EAC in progress (session 2 of 3), result recorded, counselling continues'));

        $result = $this->latestResult();
        $this->assertFalse($result->is_post_eac_repeat);
        $this->assertNull($result->eac_episode_id);
        $this->assertFalse($result->flagged_for_clinical_review);
        $this->assertSame(1, EacEpisode::where('patient_id', $this->patient->id)->count(), 'no second episode');
        $this->assertSame('active', $episode->fresh()->status);
        $this->assertNull($episode->fresh()->repeat_viral_load_result_id);
        $this->assertSame($episodeBefore, DB::table('eac_episodes')->orderBy('id')->get()->toJson(), 'episode untouched, so nothing new to sync');
        $this->assertSame($sessionsBefore, DB::table('eac_sessions')->orderBy('id')->get()->toJson());
    }

    public function test_high_after_session_3_flags_for_clinical_review_and_syncs(): void
    {
        $episode = $this->openEpisode(completed: 3);

        $response = $this->enter(9000);
        $response->assertSessionHas('warning', fn ($m) => str_contains($m, 'Suspected treatment failure: clinical review'));

        $result = $this->latestResult();
        $episode->refresh();
        $this->assertTrue($result->is_post_eac_repeat);
        $this->assertTrue($result->flagged_for_clinical_review);
        $this->assertSame($episode->id, $result->eac_episode_id);
        $this->assertSame('flagged_for_regimen_switch', $episode->status, 'status key unchanged');
        $this->assertSame('Suspected treatment failure: clinical review', $episode->statusLabel());
        $this->assertSame('pending', $episode->sync_status);
        $this->assertTrue($episode->last_modified_at->gt('2026-01-05 08:00:00'));
    }

    public function test_suppressed_after_session_3_resolves_normally_and_is_not_closed_early(): void
    {
        $episode = $this->openEpisode(completed: 3);

        $response = $this->enter(40);
        $response->assertSessionMissing('warning');

        $result = $this->latestResult();
        $episode->refresh();
        $this->assertSame('resolved_suppressed', $episode->status);
        $this->assertTrue($result->is_post_eac_repeat);
        $this->assertFalse($episode->wasClosedEarly());
        $this->assertSame(3, $episode->sessions()->where('completion_status', 'completed')->count());
        $this->assertSame('pending', $episode->sync_status);
        $this->assertFalse(AuditLog::where('action', 'eac_closed_early')->exists());
    }

    public function test_no_open_episode_high_vl_still_opens_a_new_episode(): void
    {
        $this->enter(9000)->assertSessionHas('warning', fn ($m) => str_contains($m, 'Adherence counselling (EAC Episode) automatically triggered'));

        $result = $this->latestResult();
        $episode = EacEpisode::where('patient_id', $this->patient->id)->firstOrFail();
        $this->assertSame($episode->id, $result->eac_episode_id);
        $this->assertFalse($result->is_post_eac_repeat);
        $this->assertSame('active', $episode->status);
        $this->assertSame(1, $episode->sessions()->count());
    }

    public function test_no_open_episode_suppressed_is_an_ordinary_result(): void
    {
        $this->enter(40)->assertRedirect();

        $result = $this->latestResult();
        $this->assertNull($result->eac_episode_id);
        $this->assertFalse($result->is_post_eac_repeat);
        $this->assertSame(0, EacEpisode::count());
    }

    public function test_a_closed_episode_is_not_reopened_by_a_later_high_vl(): void
    {
        $episode = $this->openEpisode(completed: 0);
        $this->enter(40)->assertRedirect();                  // closes it
        $this->enter(8000, ['sample_date' => '2026-07-01', 'result_date' => '2026-07-02'])->assertRedirect(); // new high

        $this->assertSame('resolved_suppressed', $episode->fresh()->status);
        $this->assertSame(2, EacEpisode::where('patient_id', $this->patient->id)->count(), 'a fresh episode for the new high VL');
    }

    public function test_wrong_form_values_are_ignored_and_the_server_decides(): void
    {
        $episode = $this->openEpisode(completed: 1);
        $other = Patient::create([
            'art_number' => 'ART-OTHER', 'first_name' => 'Other', 'last_name' => 'Pt', 'sex' => 'male', 'age' => 40,
            'art_start_date' => '2022-03-01', 'facility_id' => $this->patient->facility_id, 'county_id' => $this->patient->county_id,
            'state_id' => $this->patient->state_id, 'status' => 'active',
        ]);
        $foreignEpisode = EacEpisode::create(['patient_id' => $other->id, 'viral_load_result_id' => $episode->viral_load_result_id, 'status' => 'sessions_completed']);

        // High VL before session 3, but the form claims "post-EAC repeat" of someone else's episode.
        $this->enter(9000, ['is_post_eac_repeat' => 1, 'eac_episode_id' => $foreignEpisode->id])->assertRedirect();

        $result = $this->latestResult();
        $this->assertFalse($result->is_post_eac_repeat);
        $this->assertNull($result->eac_episode_id);
        $this->assertFalse($result->flagged_for_clinical_review);
        $this->assertSame('sessions_completed', $foreignEpisode->fresh()->status, 'other patient\'s episode untouched');
        $this->assertSame('active', $episode->fresh()->status);

        // Form says "not a repeat" with no episode, but an episode is open: the server still closes it.
        $this->enter(40, ['is_post_eac_repeat' => 0, 'eac_episode_id' => ''])->assertRedirect();
        $this->assertSame('resolved_suppressed', $episode->fresh()->status);
    }

    public function test_the_entry_form_explains_the_open_episode(): void
    {
        $this->openEpisode(completed: 1);

        $this->actingAs($this->labTech)->get('/viral-load/create?patient_id='.$this->patient->id)
            ->assertOk()
            ->assertSee('open EAC episode')
            ->assertSee('EAC closed early, pending sessions cancelled', false)
            ->assertSee('result recorded, counselling continues', false)
            ->assertDontSee('name="is_post_eac_repeat"', false);
    }

    public function test_the_open_episode_stays_in_the_eac_queue_and_off_the_vl_due_list(): void
    {
        $episode = $this->openEpisode(completed: 1);
        $this->enter(9000, ['sample_date' => today()->subDay()->toDateString(), 'result_date' => today()->toDateString()])->assertRedirect();

        $this->actingAs($this->labTech)->get('/dashboard')->assertOk();
        $this->assertTrue(EacEpisode::whereIn('status', EacEpisode::OPEN_STATUSES)->whereKey($episode->id)->exists());
        $this->assertFalse(app(VlDueService::class)->due()->contains(fn ($i) => $i->patient->id === $this->patient->id), 'ordinary high result has no routine due date');
    }

    public function test_a_cancelled_session_cannot_be_completed(): void
    {
        $episode = $this->openEpisode(completed: 0);
        $this->enter(40)->assertRedirect();
        $session = $episode->sessions()->first();

        $clinician = User::create([
            'name' => 'Dr. Mercy', 'email' => 'mercy@clinic.org', 'role' => UserRole::CLINICIAN,
            'password' => Hash::make('Password@123'), 'must_change_password' => false, 'is_active' => true,
        ]);

        $this->actingAs($clinician)->put(route('eac.sessions.update', $session), [
            'session_date' => '2026-06-10', 'action_plan' => 'x',
        ])->assertSessionHas('error');

        $this->assertSame('cancelled', $session->fresh()->completion_status);
    }
}
