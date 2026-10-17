<?php

namespace App\Http\Controllers;

use App\Services\AiInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiInsightController extends Controller
{
    protected AiInsightService $aiInsightService;

    public function __construct(AiInsightService $aiInsightService)
    {
        $this->aiInsightService = $aiInsightService;
    }

    /**
     * Generate / refresh clinical summary (AJAX & Form support).
     */
    public function generateSummary(Request $request): JsonResponse
    {
        $forceRefresh = $request->boolean('force_refresh', true);
        $result = $this->aiInsightService->getClinicalSummary($forceRefresh);

        return response()->json([
            'success' => true,
            'summary' => $result['summary'],
            'generated_at' => $result['generated_at'],
            'from_cache' => $result['from_cache'],
        ]);
    }

    /**
     * Get current cached summary.
     */
    public function getSummary(): JsonResponse
    {
        $result = $this->aiInsightService->getClinicalSummary(false);

        return response()->json([
            'success' => true,
            'summary' => $result['summary'],
            'generated_at' => $result['generated_at'],
            'from_cache' => $result['from_cache'],
        ]);
    }

    /**
     * Display Staff Q&A Assistant page.
     */
    public function qaIndex(): View
    {
        $aggregatedData = $this->aiInsightService->getAggregatedData();

        $sampleQuestions = [
            'How many pregnant or breastfeeding patients are due for VL testing this month?',
            'What is our pediatric viral suppression rate compared to adults?',
            'Are there any patterns or reasons in recent sample rejections at the lab?',
            'What percentage of patients have completed all 3 EAC sessions and are due for repeat VL?',
            'How many patients are flagged for clinical regimen switch review?',
        ];

        return view('insights.qa', [
            'aggregatedData' => $aggregatedData,
            'sampleQuestions' => $sampleQuestions,
        ]);
    }

    /**
     * Handle staff Q&A question submission.
     */
    public function askQa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => 'required|string|min:3|max:500',
        ]);

        $result = $this->aiInsightService->askQuestion($validated['question']);

        return response()->json([
            'success' => true,
            'question' => $result['question'],
            'answer' => $result['answer'],
            'answered_at' => $result['answered_at'],
        ]);
    }

    /**
     * Return the exact anonymized JSON payload sent to Gemini API for privacy audit.
     */
    public function previewPayload(): JsonResponse
    {
        $payload = $this->aiInsightService->getAggregatedData();

        return response()->json([
            'privacy_notice' => '100% Anonymized Aggregated Data Payload — Zero Patient Identifiable Information (PII)',
            'generated_at' => now()->toIso8601String(),
            'payload' => $payload,
        ]);
    }
}
