<?php

namespace App\Models;

use App\Services\ViralLoadEngine;
use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Patient extends Model
{
    use HasFactory, HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'art_number',
        'first_name',
        'last_name',
        'sex',
        'age',
        'dob',
        'phone',
        'address',
        'art_start_date',
        'is_pregnant',
        'is_breastfeeding',
        'pregnancy_onset_at',
        'state_id',
        'county_id',
        'facility_id',
        'status',
        'created_by',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'age' => 'integer',
        'dob' => 'date',
        'art_start_date' => 'date',
        'is_pregnant' => 'boolean',
        'is_breastfeeding' => 'boolean',
        'pregnancy_onset_at' => 'datetime',
        'last_modified_at' => 'datetime',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function county(): BelongsTo
    {
        return $this->belongsTo(County::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function regimenHistories(): HasMany
    {
        return $this->hasMany(RegimenHistory::class)->orderBy('start_date', 'desc');
    }

    public function activeRegimen(): HasOne
    {
        return $this->hasOne(RegimenHistory::class)
            ->whereNull('end_date')
            ->latestOfMany(['start_date', 'created_at']);
    }

    public function viralLoadResults(): HasMany
    {
        return $this->hasMany(ViralLoadResult::class)->orderBy('sample_date', 'desc');
    }

    public function latestViralLoadResult(): HasOne
    {
        return $this->hasOne(ViralLoadResult::class)
            ->latestOfMany(['sample_date', 'created_at']);
    }

    public function eacEpisodes(): HasMany
    {
        return $this->hasMany(EacEpisode::class)->latest();
    }

    public function activeEacEpisode(): HasOne
    {
        return $this->hasOne(EacEpisode::class)
            ->whereIn('status', ['active', 'sessions_completed'])
            ->latestOfMany('created_at');
    }

    public function sampleCollections(): HasMany
    {
        return $this->hasMany(SampleCollection::class)->orderBy('collection_date', 'desc');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class)->orderBy('appointment_date', 'desc');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationLog::class)->orderBy('created_at', 'desc');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function isPediatric(): bool
    {
        return $this->age < 15;
    }

    public function ageCohort(): string
    {
        return $this->age < 15 ? 'Child (<15)' : 'Adult (15+)';
    }

    public function monthsOnArt(): int
    {
        if (!$this->art_start_date) {
            return 0;
        }

        return (int) Carbon::parse($this->art_start_date)->diffInMonths(now());
    }

    public function isImmediateVlRequired(): bool
    {
        if (!$this->is_pregnant) {
            return false;
        }

        // Established ART patient who became pregnant
        if ($this->pregnancy_onset_at) {
            $latestTest = $this->latestViralLoadResult;
            if (!$latestTest) {
                return true;
            }

            // If latest test sample was taken before the recorded pregnancy onset date
            return Carbon::parse($latestTest->sample_date)->lt($this->pregnancy_onset_at->copy()->startOfDay());
        }

        return false;
    }

    public function effectiveNextDueDate(): ?Carbon
    {
        if ($this->isImmediateVlRequired()) {
            return today();
        }

        if ($this->latestViralLoadResult?->next_due_date) {
            return Carbon::parse($this->latestViralLoadResult->next_due_date);
        }

        if (! $this->latestViralLoadResult) {
            $initial = app(ViralLoadEngine::class)->calculateInitialDueDate($this);

            return $initial ? Carbon::parse($initial['due_date']) : null;
        }

        return null;
    }

    public function currentVlDueReason(): string
    {
        if ($this->isImmediateVlRequired()) {
            return 'Established ART patient with pregnancy onset: Immediate VL test indicated (PMTCT).';
        }

        if ($this->latestViralLoadResult?->algorithm_reason) {
            return $this->latestViralLoadResult->algorithm_reason;
        }

        if (! $this->latestViralLoadResult) {
            $initial = app(ViralLoadEngine::class)->calculateInitialDueDate($this);

            if ($initial) {
                return $initial['reason'];
            }
        }

        return 'Baseline VL test pending.';
    }

    /**
     * Preserves immutable history: ends previous regimen and starts a new one
     */
    public function assignNewRegimen(
        string $regimenName,
        string $regimenLine = '1st Line',
        ?string $startDate = null,
        ?string $reason = null,
        ?string $userId = null
    ): RegimenHistory {
        $startDate = $startDate ?? now()->toDateString();

        // Close any currently active regimen
        $active = $this->activeRegimen;
        if ($active) {
            $active->update([
                'end_date' => $startDate,
                'last_modified_at' => now(),
            ]);
        }

        // Create new active regimen record
        return $this->regimenHistories()->create([
            'regimen_name' => $regimenName,
            'regimen_line' => $regimenLine,
            'start_date' => $startDate,
            'end_date' => null,
            'reason_for_change' => $reason,
            'recorded_by' => $userId ?? auth()->id(),
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);
    }
}
