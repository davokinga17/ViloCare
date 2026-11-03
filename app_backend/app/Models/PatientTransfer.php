<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use App\Traits\ScopedByFacility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hand-over of a patient between facilities. The patient row itself moves on acceptance (see
 * App\Services\PatientTransferService); this row is visible to both facilities (sender and destination).
 */
class PatientTransfer extends Model
{
    use Auditable, HasUuidAndSync, ScopedByFacility;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const CANCELLED = 'cancelled';

    protected static string $facilityScopeKind = 'transfer';

    protected $fillable = [
        'id',
        'patient_id',
        'from_facility_id',
        'to_facility_id',
        'transfer_date',
        'letter_reference',
        'notes',
        'status',
        'requested_by',
        'accepted_by',
        'accepted_at',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'accepted_at' => 'datetime',
        'last_modified_at' => 'datetime',
    ];

    /**
     * The patient, WITHOUT the facility scope: the destination must be able to read the (minimal) identity of a
     * patient who is not theirs yet. Callers select only the columns they show.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    public function fromFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'from_facility_id');
    }

    public function toFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'to_facility_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
