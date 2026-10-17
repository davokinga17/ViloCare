<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class County extends Model
{
    use HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'state_id',
        'name',
        'code',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'last_modified_at' => 'datetime',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }
}
