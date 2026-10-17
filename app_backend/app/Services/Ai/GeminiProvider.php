<?php

namespace App\Services\Ai;

use App\Support\Integrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiProvider implements AiProviderInterface
{
    protected ?string $apiKey;
    protected string $model;
    protected int $timeout;
    protected int $maxTokens;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model = Integrations::geminiModel();
        $this->timeout = (int) config('services.gemini.timeout', 30);
        $this->maxTokens = (int) config('services.gemini.max_tokens', 2048);
    }

    /**
     * Generate a concise, plain-language clinical insights summary from aggregated clinic data.
     */
    public function generateClinicalSummary(array $aggregatedData): string
    {
        if (empty($this->apiKey)) {
            return "⚠️ **AI Insights Configuration Required**: No Gemini API key is saved on this computer. An administrator can add it under Settings → Integrations to enable automated summaries.";
        }

        $systemInstruction = <<<TEXT
You are the ViLoCare Clinical Decision-Support Assistant for an ART (HIV) Clinic.
You are given aggregated, 100% anonymized clinic operational and clinical metrics.
Your purpose is descriptive and analytical:
1. Provide a concise, clear clinical summary.
2. Structure the summary with short bullet points:
   - 🎯 **Cohort & Suppression Overview** (Total cohort, overall suppression vs pediatric/adult/pregnant rates)
   - ⚠️ **Critical Priorities & Adherence** (Unsuppressed patients, active EAC completion status, flagged for clinical switch)
   - 📋 **Laboratory & Appointments** (Specimens in transit, sample rejection rate/reasons, upcoming/overdue VL visits)
   - 💡 **Actionable Clinical Recommendations** (1-3 direct steps for clinicians and clinic managers this week)
3. Keep the total output professional, concise (under 250 words), and easy to read on a dashboard.
4. Never assume or hallucinate patient-level identifiers. All data is aggregated statistics.
TEXT;

        $userPrompt = "Please analyze the following anonymized clinic dataset and provide an executive clinical insight summary:\n\n" . json_encode($aggregatedData, JSON_PRETTY_PRINT);

        return $this->callGeminiApi($systemInstruction, $userPrompt);
    }

    /**
     * Answer a staff member's natural-language question based strictly on aggregated clinic data.
     */
    public function answerStaffQuestion(string $question, array $aggregatedData): string
    {
        if (empty($this->apiKey)) {
            return "⚠️ **AI Configuration Required**: No Gemini API key is saved on this computer. An administrator can add it under Settings → Integrations to use the Staff Q&A Assistant.";
        }

        $systemInstruction = <<<TEXT
You are the ViLoCare Staff Clinical Q&A Assistant for an ART (HIV) Clinic.
You answer questions from clinic staff (clinicians, medical officers, data officers) STRICTLY using the anonymized, aggregated clinic data provided in the prompt context.
Guidelines:
- Provide clear, direct, and factual answers based ONLY on the provided aggregated statistics.
- If the requested information is not covered by the aggregated dataset, clearly state: "This metric is not available in the current aggregated clinic statistics."
- Never speculate about specific individual patients, names, or unrecorded data.
- Format responses cleanly with bold text or bullet points where helpful.
TEXT;

        $userPrompt = "Context Aggregated Clinic Data:\n" . json_encode($aggregatedData, JSON_PRETTY_PRINT) . "\n\nStaff Question: " . $question;

        return $this->callGeminiApi($systemInstruction, $userPrompt);
    }

    /**
     * Execute POST request to Google Gemini API.
     */
    protected function callGeminiApi(string $systemInstruction, string $userPrompt): string
    {
        // The key goes in a header, not the URL, so connection errors and logs can never contain it.
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'topP' => 0.8,
                'maxOutputTokens' => $this->maxTokens,
            ]
        ];

        try {
            // Hard caps so a stalled AI call can never hang the single-threaded desktop server.
            $response = Http::connectTimeout(5)
                ->timeout($this->timeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ])
                ->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $candidate = $data['candidates'][0] ?? null;
                $finishReason = $candidate['finishReason'] ?? null;
                $text = $candidate['content']['parts'][0]['text'] ?? null;

                if ($finishReason === 'MAX_TOKENS') {
                    Log::warning("Gemini API hit MAX_TOKENS limit ({$this->maxTokens} tokens). Output may have been truncated.");
                }

                if ($text) {
                    return trim($text);
                }

                return "⚠️ Received an empty response from the AI service. Please try again.";
            }

            $statusCode = $response->status();
            $errorMessage = $response->json('error.message') ?? $response->body();

            Log::error("Gemini API error (Status {$statusCode}): {$errorMessage}");

            if ($statusCode === 429) {
                return "⚠️ AI rate limit reached. Please wait a moment before asking another question or regenerating the summary.";
            }

            if ($statusCode === 404) {
                return "⚠️ **AI Model Not Available (HTTP 404)**: The model `{$this->model}` isn't available to your key: open Settings > Integrations and choose another.";
            }

            if ($statusCode === 400 || $statusCode === 401 || $statusCode === 403) {
                return "⚠️ **AI Authentication Error (HTTP {$statusCode})**: `{$errorMessage}`. Please verify the Gemini key under Settings → Integrations (a valid Google AI Studio key starts with `AIza...`).";
            }

            return "⚠️ AI service error (HTTP {$statusCode}): " . ($errorMessage ?: "Service temporarily unavailable. Please try again shortly.");

        } catch (\Throwable $e) {
            Log::error("Gemini API connection exception: " . $e->getMessage());
            return "⚠️ Unable to connect to AI service: " . $e->getMessage();
        }
    }
}
