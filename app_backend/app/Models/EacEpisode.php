<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EacEpisode extends Model
{
    use HasFactory, HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'patient_id',
        'viral_load_result_id',
        'repeat_viral_load_result_id',
        'repeat_vl_due_date',
        'status',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'repeat_vl_due_date' => 'date',
        'last_modified_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function viralLoadResult(): BelongsTo
    {
        return $this->belongsTo(ViralLoadResult::class, 'viral_load_result_id');
    }

    public function repeatViralLoadResult(): BelongsTo
    {
        return $this->belongsTo(ViralLoadResult::class, 'repeat_viral_load_result_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EacSession::class)->orderBy('session_number', 'asc');
    }

    /** Statuses in which counselling / the repeat-VL wait is still going on. */
    public const OPEN_STATUSES = ['active', 'sessions_completed'];

    public function completedSessionsCount(): int
    {
        return $this->sessions()->where('completion_status', 'completed')->count();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** True once session 3 is done (the episode then waits for the post-EAC repeat VL). */
    public function sessionThreeCompleted(): bool
    {
        return $this->status === 'sessions_completed' || $this->completedSessionsCount() >= 3;
    }

    /** The session in progress, for "session X of 3" messages. */
    public function currentSessionNumber(): int
    {
        return min(3, $this->completedSessionsCount() + 1);
    }

    /** Resolved by a suppressed result before all 3 sessions were completed. */
    public function wasClosedEarly(): bool
    {
        return $this->status === 'resolved_suppressed'
            && $this->repeat_viral_load_result_id !== null
            && $this->completedSessionsCount() < 3;
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'active' => 'badge-warning',
            'sessions_completed' => 'badge-info',
            'repeat_vl_done' => 'badge-secondary',
            'resolved_suppressed' => 'badge-success',
            'flagged_for_regimen_switch' => 'badge-danger',
            default => 'badge-secondary',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Active in EAC Counselling',
            'sessions_completed' => 'Sessions Complete (Due Repeat VL)',
            'repeat_vl_done' => 'Repeat VL Test Done',
            'resolved_suppressed' => 'Resolved (Suppressed)',
            'flagged_for_regimen_switch' => 'Suspected treatment failure: clinical review',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }
}
