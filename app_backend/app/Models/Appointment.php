<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory, HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'patient_id',
        'appointment_date',
        'appointment_time',
        'reason',
        'status',
        'scheduled_by',
        'attended_at',
        'attended_by',
        'notes',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'attended_at' => 'datetime',
        'last_modified_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function attender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attended_by');
    }

    public function isToday(): bool
    {
        return $this->appointment_date->isToday();
    }

    public function isPast(): bool
    {
        return $this->appointment_date->isPast() && !$this->isToday();
    }

    public function isUpcoming(): bool
    {
        return $this->appointment_date->isFuture();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isMissed(): bool
    {
        return $this->status === 'missed' || ($this->status === 'scheduled' && $this->isPast());
    }

    public function statusBadgeClass(): string
    {
        if ($this->status === 'scheduled' && $this->isPast()) {
            return 'badge-danger';
        }

        return match ($this->status) {
            'scheduled' => 'badge-info',
            'completed' => 'badge-success',
            'missed' => 'badge-danger',
            'rescheduled' => 'badge-warning',
            'cancelled' => 'badge-secondary',
            default => 'badge-secondary',
        };
    }

    public function statusLabel(): string
    {
        if ($this->status === 'scheduled' && $this->isPast()) {
            return 'Overdue / Missed';
        }

        return match ($this->status) {
            'scheduled' => 'Scheduled',
            'completed' => 'Attended / Completed',
            'missed' => 'Missed Appointment',
            'rescheduled' => 'Rescheduled',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status),
        };
    }
}
