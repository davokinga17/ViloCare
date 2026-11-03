<?php

namespace App\Models;

use App\Services\AccessScope;
use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facility extends Model
{
    use HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'county_id',
        'name',
        'code',
        'facility_level',
        'sync_status',
        'last_modified_at',
    ];

    /** Only locations the logged-in user may choose or filter by (see AccessScope). */
    public function scopeAllowed(Builder $query): Builder
    {
        $scope = AccessScope::current();

        return $scope->isAll() ? $query : $query->whereIn('id', $scope->facilityIds());
    }

    protected $casts = [
        'last_modified_at' => 'datetime',
    ];

    public function county(): BelongsTo
    {
        return $this->belongsTo(County::class);
    }

    /** Testing laboratories located inside this facility (see TestingLaboratory; not a facility attribute). */
    public function testingLaboratories(): HasMany
    {
        return $this->hasMany(TestingLaboratory::class);
    }
}
