<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
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
