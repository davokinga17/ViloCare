<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\SyncService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * An EAC episode resolved or flagged by a repeat viral load must sync, and the repair command
 * must re-deliver episodes that were saved silently before that was fixed.
 */
class EacEpisodeSyncTest extends TestCase
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
        $this->patient = $this->patient('ART-EAC-1', $facility);
    }

    private function patient(string $art, Facility $facility): Patient
    {
        return Patient::create([
            'art_number' => $art, 'first_name' => 'Test', 'last_name' => $art, 'sex' => 'female', 'age' => 30,
            'art_start_date' => '2022-03-01', 'facility_id' => $facility->id, 'county_id' => $facility->county_id,
            'state_id' => $facility->county->state_id, 'status' => 'active',
        ]);
    }

    private function postVl(int $value): void
    {
        $this->actingAs($this->labTech)->post('/viral-load', [
            'patient_id' => $this->patient->id, 'sample_date' => today()->toDateString(), 'result_date' => today()->toDateString(),
            'result_value' => $value, 'sample_type' => 'Plasma', 'lab_name' => 'Kapoeta Civil Hospital',
        ])->assertRedirect();
    }

    /** An episode as the cloud had it before the fix: synced, with an old last_modified_at. */
    private function syncedEpisodeOpenSince(string $when): EacEpisode
    {
        $this->postVl(5000); // high VL opens the episode through the engine
        $episode = EacEpisode::where('patient_id', $this->patient->id)->firstOrFail();
        DB::table('eac_episodes')->where('id', $episode->id)->update(['sync_status' => 'synced', 'last_modified_at' => $when]);

        return $episode->fresh();
    }

    public function test_repeat_result_marks_the_episode_pending_with_a_newer_last_modified(): void
    {
        $episode = $this->syncedEpisodeOpenSince('2026-01-01 08:00:00');

        $this->actingAs($this->labTech)->post('/viral-load', [
            'patient_id' => $this->patient->id, 'sample_date' => today()->addDay()->toDateString(), 'result_date' => today()->addDay()->toDateString(),
            'result_value' => 40, 'sample_type' => 'Plasma', 'lab_name' => 'Kapoeta Civil Hospital',
            'eac_episode_id' => $episode->id, 'is_post_eac_repeat' => 1,
        ])->assertRedirect();

        $episode->refresh();
        $this->assertSame('resolved_suppressed', $episode->status);
        $this->assertNotNull($episode->repeat_viral_load_result_id);
        $this->assertSame('pending', $episode->sync_status);
        $this->assertTrue($episode->last_modified_at->gt('2026-01-01 08:00:00'));

        $pending = app(SyncService::class)->getPendingLocalPayload()['tables']['eac_episodes'];
        $this->assertContains($episode->id, array_column($pending, 'id'), 'it is part of the next push');
    }

    public function test_repeat_result_that_is_still_high_flags_the_episode_and_syncs_it(): void
    {
        $episode = $this->syncedEpisodeOpenSince('2026-01-01 08:00:00');
        DB::table('eac_episodes')->where('id', $episode->id)->update(['status' => 'sessions_completed']); // session 3 done

        $this->actingAs($this->labTech)->post('/viral-load', [
            'patient_id' => $this->patient->id, 'sample_date' => today()->addDay()->toDateString(), 'result_date' => today()->addDay()->toDateString(),
            'result_value' => 7000, 'sample_type' => 'Plasma', 'lab_name' => 'Kapoeta Civil Hospital',
            'eac_episode_id' => $episode->id, 'is_post_eac_repeat' => 1,
        ])->assertRedirect();

        $episode->refresh();
        $this->assertSame('flagged_for_regimen_switch', $episode->status);
        $this->assertSame('pending', $episode->sync_status);
    }

    /** @return array{stale: EacEpisode, healthy: EacEpisode, mismatch: EacEpisode} */
    private function repairFixture(): array
    {
        $make = function (string $art, string $status, int $value, string $episodeModified) {
            $patient = $this->patient($art, Facility::where('code', 'KCH-001')->firstOrFail());
            $trigger = ViralLoadResult::create([
                'patient_id' => $patient->id, 'sample_date' => '2026-09-01', 'result_date' => '2026-09-01', 'result_value' => 6000,
                'is_suppressed' => false, 'lab_name' => 'Kapoeta Civil Hospital', 'sample_type' => 'Plasma',
            ]);
            $repeat = ViralLoadResult::create([
                'patient_id' => $patient->id, 'sample_date' => '2026-10-05', 'result_date' => '2026-10-05', 'result_value' => $value,
                'is_suppressed' => $value < 1000, 'is_post_eac_repeat' => true, 'lab_name' => 'Kapoeta Civil Hospital', 'sample_type' => 'Plasma',
            ]);
            $episode = EacEpisode::create([
                'patient_id' => $patient->id, 'viral_load_result_id' => $trigger->id,
                'repeat_viral_load_result_id' => $repeat->id, 'status' => $status,
            ]);
            DB::table('viral_load_results')->where('id', $repeat->id)->update(['created_at' => '2026-10-08 20:18:08']);
            DB::table('eac_episodes')->where('id', $episode->id)->update(['sync_status' => 'synced', 'last_modified_at' => $episodeModified]);

            return $episode->fresh();
        };

        return [
            'stale' => $make('ART-STALE', 'resolved_suppressed', 800, '2026-10-04 20:02:35'),   // result newer than last change
            'healthy' => $make('ART-HEALTHY', 'resolved_suppressed', 800, '2026-10-08 21:00:00'), // already tracked after the result
            'mismatch' => $make('ART-MISMATCH', 'resolved_suppressed', 9000, '2026-10-04 20:02:35'), // status contradicts the result
        ];
    }

    private function snapshot(): string
    {
        return DB::table('eac_episodes')->orderBy('id')->get()->toJson();
    }

    public function test_dry_run_is_the_default_lists_exact_rows_and_changes_nothing(): void
    {
        $f = $this->repairFixture();
        $before = $this->snapshot();

        $this->artisan('vilocare:repair-eac-sync')
            ->expectsOutputToContain($f['stale']->id)
            ->expectsOutputToContain('SKIPPED')
            ->doesntExpectOutputToContain($f['healthy']->id)
            ->assertExitCode(0);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_asks_for_confirmation_and_declining_changes_nothing(): void
    {
        $this->repairFixture();
        $before = $this->snapshot();

        $this->artisan('vilocare:repair-eac-sync', ['--apply' => true])
            ->expectsConfirmation('Re-save 1 episode(s)? No values change; only last_modified_at moves forward.', 'no')
            ->assertExitCode(1);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_backs_up_then_moves_only_the_stale_episode_forward_without_changing_values(): void
    {
        $f = $this->repairFixture();
        $values = fn (EacEpisode $e) => $e->only(['status', 'repeat_viral_load_result_id', 'repeat_vl_due_date', 'patient_id', 'viral_load_result_id']);
        $staleBefore = $values($f['stale']);

        $this->artisan('vilocare:repair-eac-sync', ['--apply' => true])
            ->expectsConfirmation('Re-save 1 episode(s)? No values change; only last_modified_at moves forward.', 'yes')
            ->assertExitCode(0);

        $stale = $f['stale']->fresh();
        $this->assertSame($staleBefore, $values($stale));
        $this->assertSame('pending', $stale->sync_status);
        $this->assertTrue($stale->last_modified_at->gt('2026-10-08 20:18:08'), 'now newer than the repeat result');

        // The other two are untouched (a status that contradicts its result is never re-saved).
        $this->assertEquals($f['healthy']->last_modified_at, $f['healthy']->fresh()->last_modified_at);
        $this->assertEquals($f['mismatch']->last_modified_at, $f['mismatch']->fresh()->last_modified_at);

        $backups = glob(storage_path('app/backups/repair-eac-sync-*.json'));
        $this->assertNotEmpty($backups, 'row backup written before the change');
        $saved = json_decode(file_get_contents(end($backups)), true);
        $this->assertSame([$f['stale']->id], array_column($saved, 'id'));
        $this->assertSame('2026-10-04 20:02:35', $saved[0]['last_modified_at'], 'backup holds the pre-repair value');
        array_map('unlink', $backups);

        // Running it again finds nothing: the row is no longer stale.
        $this->artisan('vilocare:repair-eac-sync')->expectsOutputToContain('SKIPPED')->assertExitCode(0);
    }

    public function test_list_early_closed_is_read_only_and_finds_closed_episodes_with_pending_sessions(): void
    {
        $f = $this->repairFixture();
        $f['stale']->sessions()->create([
            'patient_id' => $f['stale']->patient_id, 'session_number' => 2, 'session_date' => '2026-10-01', 'completion_status' => 'pending',
        ]);
        $before = $this->snapshot();
        $sessions = DB::table('eac_sessions')->get()->toJson();

        $this->artisan('vilocare:list-early-closed-eac')
            ->expectsOutputToContain($f['stale']->id)
            ->doesntExpectOutputToContain($f['healthy']->id)
            ->assertExitCode(0);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($sessions, DB::table('eac_sessions')->get()->toJson());
    }
    public function test_a_clinic_with_the_stale_copy_adopts_the_status_after_the_resave(): void
    {
        $f = $this->repairFixture();
        // Clinic's copy: still waiting for the repeat result (the stale state seen on the clinic).
        $cloudCopy = $f['stale']->getAttributes();
        DB::table('eac_episodes')->where('id', $f['stale']->id)->update(['status' => 'sessions_completed', 'repeat_viral_load_result_id' => null]);

        // Before the repair the pulled cloud copy has the same last_modified_at: ignored.
        $before = app(SyncService::class)->applyRemotePull(['eac_episodes' => [$cloudCopy]]);
        $this->assertSame(1, $before['unchanged']);
        $this->assertSame('sessions_completed', $f['stale']->fresh()->status);

        // After the cloud re-saves it (newer last_modified_at) the same pull applies.
        $cloudCopy['last_modified_at'] = now()->addMinute()->toDateTimeString();
        $after = app(SyncService::class)->applyRemotePull(['eac_episodes' => [$cloudCopy]]);
        $this->assertSame(1, $after['applied']);
        $this->assertSame('resolved_suppressed', $f['stale']->fresh()->status);
        $this->assertNotNull($f['stale']->fresh()->repeat_viral_load_result_id);
    }
}
