<?php

namespace App\Scopes;

use App\Services\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Applies AccessScope::current() to a model automatically. Kinds:
 *   patient     the patients table itself
 *   patient_id  a table that belongs to a patient through patient_id
 *   sample      sample_collections (own facility, or the patient's)
 *   sample_id   a table that belongs to a sample through sample_collection_id
 */
class FacilityScope implements Scope
{
    public function __construct(private string $kind) {}

    public function apply(Builder $builder, Model $model): void
    {
        $scope = AccessScope::current();
        if ($scope->isAll()) {
            return;
        }

        $table = $model->getTable();
        match ($this->kind) {
            'patient' => $scope->constrainPatients($builder, $table.'.'),
            'patient_id' => $builder->whereIn($table.'.patient_id', $scope->patientIds()),
            'sample' => $scope->constrainSamples($builder, $table.'.'),
            'sample_id' => $builder->whereIn($table.'.sample_collection_id', $scope->sampleIds()),
        };
    }
}
