<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SampleRejection extends Model
{
    use HasFactory, HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'sample_collection_id',
        'rejection_date',
        'reason',
        'action_taken',
        'corrective_action',
        'rejected_by',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'rejection_date' => 'date',
        'last_modified_at' => 'datetime',
    ];

    public function sampleCollection(): BelongsTo
    {
        return $this->belongsTo(SampleCollection::class);
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
