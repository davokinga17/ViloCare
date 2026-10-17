<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViralLoadResult extends Model
{
    use Auditable, HasFactory, HasUuidAndSync;

    protected $fillable = [
        'id',
        'patient_id',
        'sample_date',
        'result_date',
        'result_value',
        'is_target_not_detected',
        'is_suppressed',
        'lab_name',
        'sample_type',
        'requesting_clinician_id',
        'recorded_by',
        'notes',
        'is_post_eac_repeat',
        'eac_episode_id',
        'next_due_date',
        'algorithm_reason',
        'flagged_for_clinical_review',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'sample_date' => 'date',
        'result_date' => 'date',
        'result_value' => 'integer',
        'is_target_not_detected' => 'boolean',
        'is_suppressed' => 'boolean',
        'is_post_eac_repeat' => 'boolean',
        'next_due_date' => 'date',
        'flagged_for_clinical_review' => 'boolean',
        'last_modified_at' => 'datetime',
    ];

    /**
     * Only each patient's most recent result: latest sample_date, then created_at, then id.
     * Single NOT EXISTS subquery - no per-patient loop.
     *
     * Built only from portable column comparisons (no COALESCE with text literals, no
     * database-specific functions) so it runs unchanged on SQLite and PostgreSQL. A NULL
     * created_at counts as the oldest.
     */
    public function scopeLatestPerPatient(Builder $query, ?string $from = null, ?string $to = null): Builder
    {
        $table = $this->getTable();

        // Optional result_date window: only results inside it compete to be "latest".
        if ($from) {
            $query->whereDate("{$table}.result_date", '>=', $from);
        }
        if ($to) {
            $query->whereDate("{$table}.result_date", '<=', $to);
        }

        return $query->whereNotExists(function ($newer) use ($table, $from, $to) {
            $newer->selectRaw('1')
                ->from("{$table} as newer")
                ->whereColumn('newer.patient_id', "{$table}.patient_id")
                ->when($from, fn ($q) => $q->whereDate('newer.result_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('newer.result_date', '<=', $to))
                ->where(function ($later) use ($table) {
                    $later->whereColumn('newer.sample_date', '>', "{$table}.sample_date")
                        ->orWhere(function ($sameDay) use ($table) {
                            $sameDay->whereColumn('newer.sample_date', "{$table}.sample_date")
                                ->where(function ($tie) use ($table) {
                                    // Created later (a row with a timestamp beats one without)...
                                    $tie->whereColumn('newer.created_at', '>', "{$table}.created_at")
                                        ->orWhere(function ($stamped) use ($table) {
                                            $stamped->whereNotNull('newer.created_at')->whereNull("{$table}.created_at");
                                        })
                                        // ...or created at the same instant: the larger id wins.
                                        ->orWhere(function ($sameStamp) use ($table) {
                                            $sameStamp->where(function ($equal) use ($table) {
                                                $equal->whereColumn('newer.created_at', "{$table}.created_at")
                                                    ->orWhere(function ($bothNull) use ($table) {
                                                        $bothNull->whereNull('newer.created_at')->whereNull("{$table}.created_at");
                                                    });
                                            })->whereColumn('newer.id', '>', "{$table}.id");
                                        });
                                });
                        });
                });
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function requestingClinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requesting_clinician_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function eacEpisode(): BelongsTo
    {
        return $this->belongsTo(EacEpisode::class, 'eac_episode_id');
    }

    public function getFormattedResultAttribute(): string
    {
        if ($this->is_target_not_detected || $this->result_value === 0) {
            return 'LDL / Target Not Detected (<20 copies/ml)';
        }

        return number_format($this->result_value).' copies/ml';
    }

    public function suppressionStatusLabel(): string
    {
        if ($this->is_suppressed) {
            return 'Suppressed (<1,000 c/ml)';
        }

        return 'Unsuppressed / High VL (≥1,000 c/ml)';
    }
}
