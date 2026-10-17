<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SampleCollection extends Model
{
    use HasFactory, HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'sample_code',
        'patient_id',
        'collection_date',
        'sample_type',
        'collector_id',
        'collector_name',
        'health_facility_code',
        'facility_id',
        'status',
        'sample_reception_date',
        'received_by',
        'notes',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'collection_date' => 'date',
        'sample_reception_date' => 'date',
        'last_modified_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function rejection(): HasOne
    {
        return $this->hasOne(SampleRejection::class, 'sample_collection_id');
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isReceived(): bool
    {
        return in_array($this->status, ['received_at_lab', 'processed']);
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'collected' => 'badge-secondary',
            'in_transit' => 'badge-info',
            'received_at_lab' => 'badge-success',
            'rejected' => 'badge-danger',
            'processed' => 'badge-primary',
            default => 'badge-secondary',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'collected' => 'Collected (At Facility)',
            'in_transit' => 'In Transit to Lab',
            'received_at_lab' => 'Received at Lab',
            'rejected' => 'Sample Rejected',
            'processed' => 'Analysis Completed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /**
     * Generate unique sample accession code: SMP-YYYYMM-XXXX
     */
    public static function generateSampleCode(): string
    {
        $prefix = 'SMP-' . date('Ym') . '-';
        $latest = static::where('sample_code', 'like', "{$prefix}%")
            ->orderBy('sample_code', 'desc')
            ->first();

        if ($latest && preg_match('/-(\d+)$/', $latest->sample_code, $matches)) {
            $nextSeq = (int) $matches[1] + 1;
        } else {
            $nextSeq = 1;
        }

        return $prefix . str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
    }
}
