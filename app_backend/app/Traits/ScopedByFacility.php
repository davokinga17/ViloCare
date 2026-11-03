<?php

namespace App\Traits;

use App\Scopes\FacilityScope;

/**
 * Restricts the model to what the logged-in web user may see (see AccessScope). The model sets
 * `$facilityScopeKind` (patient, patient_id, sample or sample_id).
 */
trait ScopedByFacility
{
    public static function bootScopedByFacility(): void
    {
        static::addGlobalScope(new FacilityScope(static::$facilityScopeKind));
    }
}
