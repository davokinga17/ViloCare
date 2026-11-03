<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\SyncConflict;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pages that show raw record contents (sync conflict payloads, audit log values) are for administrators only.
 * The sync status page stays visible to every role but never shows record payloads.
 */
class SensitivePagesAccessTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'PAYLOAD-MARKER-Zq81';

    private User $admin;

    private SyncConflict $conflict;

    private ?array $staff = null;

    /** @return array<string, User> */
    private function staff(): array
    {
        return $this->staff ??= [
            'clinician' => $this->user('clin@s.org', UserRole::CLINICIAN),
            'data clerk' => $this->user('clerk@s.org', UserRole::DATA_CLERK),
            'lab technician' => $this->user('lab@s.org', UserRole::LAB_TECHNICIAN),
        ];
    }

    private function user(string $email, UserRole $role): User
    {
        return User::create([
            'name' => 'Staff', 'email' => $email, 'role' => $role,
            'facility_id' => $role === UserRole::ADMINISTRATOR ? null : Facility::first()->id,
            'password' => Hash::make('Password@123'), 'must_change_password' => false, 'is_active' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $this->admin = $this->user('admin@s.org', UserRole::ADMINISTRATOR);
        $this->conflict = SyncConflict::create([
            'table_name' => 'patients',
            'record_id' => (string) Str::uuid(),
            'local_payload' => ['first_name' => self::MARKER.'-local', 'phone' => '+211000'],
            'remote_payload' => ['first_name' => self::MARKER.'-remote'],
            'resolution_strategy' => 'last_write_wins',
        ]);
    }

    public function test_only_administrators_open_the_conflict_history(): void
    {
        foreach ($this->staff() as $label => $user) {
            $resp = $this->actingAs($user)->get(route('sync.conflicts'));
            $resp->assertForbidden();
            $resp->assertDontSee(self::MARKER, false);
        }

        $this->actingAs($this->admin)->get(route('sync.conflicts'))
            ->assertOk()
            ->assertSee(self::MARKER.'-local', false)
            ->assertSee(self::MARKER.'-remote', false);
    }

    public function test_only_administrators_resolve_conflicts(): void
    {
        foreach ($this->staff() as $label => $user) {
            $this->actingAs($user)->post(route('sync.conflicts.resolve', $this->conflict), ['strategy' => 'mark_resolved'])
                ->assertForbidden();
            $this->assertNull($this->conflict->fresh()->resolved_at, "{$label} must not resolve a conflict");
        }

        $this->actingAs($this->admin)->post(route('sync.conflicts.resolve', $this->conflict), ['strategy' => 'mark_resolved'])
            ->assertRedirect(route('sync.conflicts'));
        $this->assertNotNull($this->conflict->fresh()->resolved_at);
    }

    public function test_every_role_still_sees_the_sync_status_page_but_never_payloads(): void
    {
        foreach ($this->staff() + ['administrator' => $this->admin] as $label => $user) {
            $page = $this->actingAs($user)->get(route('sync.index'));
            $page->assertOk()->assertSee('Sync Center', false);
            $page->assertDontSee(self::MARKER, false);

            $json = $this->get(route('sync.status-check'));
            $json->assertOk();
            $this->assertStringNotContainsString(self::MARKER, $json->getContent(), "{$label}: status JSON");
            $this->assertStringNotContainsString('local_payload', $json->getContent());
        }

        $this->actingAs($this->staff()['clinician'])->get(route('sync.index'))->assertDontSee('Conflict History', false);
        $this->actingAs($this->admin)->get(route('sync.index'))->assertSee('Conflict History', false);
    }

    public function test_raw_technical_error_text_is_for_administrators_only(): void
    {
        $details = ['type' => 'general_error', 'title' => 'Sync problem', 'friendly_message' => 'Something failed.',
            'action_hint' => 'Data is safe.', 'raw_error' => 'RAW-ERROR-MARKER insert into patients values (...)'];

        $this->actingAs($this->staff()['clinician'])->withSession(['sync_error_details' => $details])
            ->get(route('sync.index'))->assertOk()
            ->assertSee('Something failed.')
            ->assertDontSee('RAW-ERROR-MARKER', false);

        $this->actingAs($this->admin)->withSession(['sync_error_details' => $details])
            ->get(route('sync.index'))->assertSee('RAW-ERROR-MARKER', false);
    }

    public function test_audit_logs_are_for_administrators_only(): void
    {
        AuditLog::log('updated_patient', 'patients', (string) Str::uuid(), ['name' => self::MARKER], ['name' => 'x'], $this->admin->id);

        foreach ($this->staff() as $label => $user) {
            $this->actingAs($user)->get(route('audit-logs.index'))->assertForbidden();
        }
        $this->actingAs($this->admin)->get(route('audit-logs.index'))->assertOk()->assertSee(self::MARKER, false);
    }

    public function test_ai_insight_payload_preview_holds_aggregates_only_and_no_patient_details(): void
    {
        $facility = Facility::first();
        Patient::create([
            'art_number' => 'ART-LEAK-1', 'first_name' => 'Leakname', 'last_name' => 'Surname', 'phone' => '+211777000999',
            'sex' => 'female', 'age' => 30, 'art_start_date' => '2023-01-01', 'facility_id' => $facility->id,
            'county_id' => $facility->county_id, 'state_id' => $facility->county->state_id, 'status' => 'active',
        ]);

        $body = $this->actingAs($this->staff()['clinician'])->get(route('insights.preview-payload'))->assertOk()->getContent();
        foreach (['ART-LEAK-1', 'Leakname', 'Surname', '+211777000999'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $this->actingAs($this->staff()['data clerk'])->get(route('insights.preview-payload'))->assertForbidden();
        $this->actingAs($this->staff()['lab technician'])->get(route('insights.preview-payload'))->assertForbidden();
    }
}
