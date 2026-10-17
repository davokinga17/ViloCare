<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EacSession extends Model
{
    use HasFactory, HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'eac_episode_id',
        'patient_id',
        'session_number',
        'session_date',
        'counselor_id',
        'barriers',
        'action_plan',
        'notes',
        'completion_status',
        'next_session_date',
        'completed_at',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'session_date' => 'date',
        'next_session_date' => 'date',
        'completed_at' => 'datetime',
        'last_modified_at' => 'datetime',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(EacEpisode::class, 'eac_episode_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }

    public function isCompleted(): bool
    {
        return $this->completion_status === 'completed';
    }
}
