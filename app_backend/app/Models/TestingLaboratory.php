<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A laboratory that tests viral load samples. Independent of facilities: it may sit inside one
 * (facility_id) or stand alone (the National Public Health Laboratory). Reference data owned by the
 * cloud and pulled by clinics; never pushed.
 */
class TestingLaboratory extends Model
{
    use HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'name',
        'code',
        'facility_id',
        'is_active',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_modified_at' => 'datetime',
    ];

    /** Active laboratories, ordered by name (the VL entry dropdown). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}
