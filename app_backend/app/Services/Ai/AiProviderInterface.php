<?php

namespace App\Services\Ai;

interface AiProviderInterface
{
    /**
     * Generate a concise, plain-language clinical insights summary from aggregated clinic data.
     *
     * @param array<string, mixed> $aggregatedData Anonymized clinic-level statistics
     * @return string Formatted plain-language summary
     */
    public function generateClinicalSummary(array $aggregatedData): string;

    /**
     * Answer a staff member's natural-language question based strictly on aggregated clinic data.
     *
     * @param string $question
     * @param array<string, mixed> $aggregatedData Anonymized clinic-level statistics
     * @return string Anonymized analytical response
     */
    public function answerStaffQuestion(string $question, array $aggregatedData): string;
}
