<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class AnalyticsService
{
    /**
     * Apply location filters to a query that has a relationship to Patient (or Patient itself).
     */
    public function applyLocationFilter(Builder $query, ?string $stateId = null, ?string $countyId = null, ?string $facilityId = null, bool $isDirectPatientQuery = false): Builder
    {
        if ($facilityId && $facilityId !== 'all') {
            return $isDirectPatientQuery
                ? $query->where('facility_id', $facilityId)
                : $query->whereHas('patient', fn ($q) => $q->where('facility_id', $facilityId));
        }

        if ($countyId && $countyId !== 'all') {
            return $isDirectPatientQuery
                ? $query->where('county_id', $countyId)
                : $query->whereHas('patient', fn ($q) => $q->where('county_id', $countyId));
        }

        if ($stateId && $stateId !== 'all') {
            return $isDirectPatientQuery
                ? $query->where('state_id', $stateId)
                : $query->whereHas('patient', fn ($q) => $q->where('state_id', $stateId));
        }

        return $query;
    }


    /**
     * Monthly suppression trend: each patient counted once per month, using their latest result
     * as of the end of that month (see SuppressionService).
     */
    public function getMonthlySuppressionTrend(?string $stateId = null, ?string $countyId = null, ?string $facilityId = null, int $months = 6): array
    {
        return app(SuppressionService::class)->monthlyTrend($this->location($stateId, $countyId, $facilityId), $months);
    }

    /**
     * Cohort suppression breakdown (Pediatric vs Adult vs PMTCT), per patient (see SuppressionService).
     */
    public function getCohortSuppressionBreakdown(?string $stateId = null, ?string $countyId = null, ?string $facilityId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        return app(SuppressionService::class)->cohorts($this->location($stateId, $countyId, $facilityId), $startDate, $endDate);
    }

    private function location(?string $stateId, ?string $countyId, ?string $facilityId): array
    {
        return ['state_id' => $stateId, 'county_id' => $countyId, 'facility_id' => $facilityId];
    }
}
