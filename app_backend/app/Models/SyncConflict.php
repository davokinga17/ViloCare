<?php

namespace App\Models;

use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncConflict extends Model
{
    use HasUuidAndSync;

    protected $fillable = [
        'id',
        'table_name',
        'record_id',
        'local_payload',
        'remote_payload',
        'resolution_strategy',
        'resolution_notes',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'local_payload' => 'array',
        'remote_payload' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
