<?php

namespace App\Models;

use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuidAndSync;

    protected $fillable = [
        'id',
        'user_id',
        'action',
        'table_name',
        'record_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'last_modified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function log(
        string $action,
        string $tableName,
        string $recordId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $userId = null
    ): self {
        return self::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);
    }
}
