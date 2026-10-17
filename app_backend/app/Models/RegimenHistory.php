<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegimenHistory extends Model
{
    use HasFactory, HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'patient_id',
        'regimen_name',
        'regimen_line',
        'start_date',
        'end_date',
        'reason_for_change',
        'recorded_by',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'last_modified_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isActive(): bool
    {
        return $this->end_date === null;
    }
}
