<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Services\Ai\AiProviderInterface;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiInsightTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $clinician;
    protected User $dataClerk;
    protected User $labTech;
    protected Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        $this->facility = Facility::firstOrFail();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@vilocare.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->clinician = User::create([
            'name' => 'Dr. Elizabeth',
            'email' => 'clinician@vilocare.org',
            'role' => UserRole::CLINICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->dataClerk = User::create([
            'name' => 'Clerk John',
            'email' => 'clerk@vilocare.org',
            'role' => UserRole::DATA_CLERK,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->labTech = User::create([
            'name' => 'Tech Sarah',
            'email' => 'lab@vilocare.org',
            'role' => UserRole::LAB_TECHNICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        // Bind mock AI provider for feature tests
        $this->app->bind(AiProviderInterface::class, function () {
            return new class implements AiProviderInterface {
                public function generateClinicalSummary(array $aggregatedData): string
                {
                    return "### Clinical Insights Summary\n- Cohort size: " . ($aggregatedData['cohort_demographics']['total_active_patients'] ?? 0) . " patients.\n- All systems running smoothly.";
                }

                public function answerStaffQuestion(string $question, array $aggregatedData): string
                {
                    return "Response to: " . $question;
                }
            };
        });
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('insights.qa.index'));
        $response->assertRedirect(route('login'));

        $responseSummary = $this->getJson(route('insights.summary.get'));
        $responseSummary->assertStatus(401);
    }

    public function test_clinician_and_admin_can_access_ai_summary_and_qa(): void
    {
        // Clinician Access
        $response = $this->actingAs($this->clinician)->get(route('insights.qa.index'));
        $response->assertStatus(200);
        $response->assertSeeText('Staff Clinical Q&A Assistant');

        $responseSummary = $this->actingAs($this->clinician)->postJson(route('insights.summary.generate'), [
            'force_refresh' => true,
        ]);
        $responseSummary->assertStatus(200);
        $responseSummary->assertJsonPath('success', true);
        $responseSummary->assertJsonStructure(['success', 'summary', 'generated_at', 'from_cache']);

        // Admin Access
        $responseAdmin = $this->actingAs($this->admin)->get(route('insights.qa.index'));
        $responseAdmin->assertStatus(200);
    }

    public function test_data_clerk_and_lab_technician_are_forbidden(): void
    {
        // Data Clerk forbidden
        $responseClerk = $this->actingAs($this->dataClerk)->get(route('insights.qa.index'));
        $responseClerk->assertStatus(403);

        // Lab Tech forbidden
        $responseLab = $this->actingAs($this->labTech)->get(route('insights.qa.index'));
        $responseLab->assertStatus(403);
    }

    public function test_qa_assistant_validates_and_answers_staff_inquiries(): void
    {
        // Missing question validation
        $responseBad = $this->actingAs($this->clinician)->postJson(route('insights.qa.ask'), [
            'question' => '',
        ]);
        $responseBad->assertStatus(422);

        // Valid question
        $responseGood = $this->actingAs($this->clinician)->postJson(route('insights.qa.ask'), [
            'question' => 'How many patients are currently enrolled?',
        ]);
        $responseGood->assertStatus(200);
        $responseGood->assertJsonPath('success', true);
        $responseGood->assertJsonPath('question', 'How many patients are currently enrolled?');
        $this->assertStringContainsString('Response to: How many patients are currently enrolled?', $responseGood->json('answer'));
    }

    public function test_preview_payload_returns_strictly_anonymized_data(): void
    {
        Patient::create([
            'art_number' => 'ART-HIDDEN-001',
            'first_name' => 'SuperSecretName',
            'last_name' => 'HiddenSurname',
            'phone' => '+211918888888',
            'address' => 'Secret Street',
            'sex' => 'female',
            'age' => 25,
            'art_start_date' => '2022-01-01',
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->clinician)->getJson(route('insights.preview-payload'));
        $response->assertStatus(200);
        $response->assertJsonPath('privacy_notice', '100% Anonymized Aggregated Data Payload — Zero Patient Identifiable Information (PII)');

        $jsonStr = $response->getContent();
        $this->assertStringNotContainsString('SuperSecretName', $jsonStr);
        $this->assertStringNotContainsString('HiddenSurname', $jsonStr);
        $this->assertStringNotContainsString('ART-HIDDEN-001', $jsonStr);
        $this->assertStringNotContainsString('+211918888888', $jsonStr);
        $this->assertStringNotContainsString('Secret Street', $jsonStr);
    }

    public function test_dashboard_does_not_contain_technical_model_labels_and_has_empty_initial_state(): void
    {
        $response = $this->actingAs($this->clinician)->get(route('dashboard'));
        $response->assertStatus(200);

        // Technical provider/model names must not be displayed in the visible UI
        $response->assertDontSee('Gemini 2.5 Flash');
        $response->assertDontSee('Google Gemini Flash');

        // Verify initial placeholder empty state exists
        $response->assertSee('ai-summary-empty');
        $response->assertSee('Generate Clinical Summary');
        $response->assertSee('btn-dismiss-ai');
    }

    public function test_manual_trigger_summary_generation_and_caching_flow(): void
    {
        // 1. Initial manual generation (without force refresh if empty will generate and cache)
        $resp1 = $this->actingAs($this->clinician)->getJson(route('insights.summary.get'));
        $resp1->assertStatus(200);
        $resp1->assertJsonPath('success', true);
        $resp1->assertJsonPath('from_cache', false);

        // 2. Second retrieval without force refresh returns from cache (e.g. after user dismissed and re-clicks Generate)
        $resp2 = $this->actingAs($this->clinician)->getJson(route('insights.summary.get'));
        $resp2->assertStatus(200);
        $resp2->assertJsonPath('success', true);
        $resp2->assertJsonPath('from_cache', true);
        $this->assertEquals($resp1->json('summary'), $resp2->json('summary'));

        // 3. Forced refresh regenerates and updates cache
        $resp3 = $this->actingAs($this->clinician)->postJson(route('insights.summary.generate'), [
            'force_refresh' => true,
        ]);
        $resp3->assertStatus(200);
        $resp3->assertJsonPath('success', true);
        $resp3->assertJsonPath('from_cache', false);
    }
}
