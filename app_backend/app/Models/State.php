<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends Model
{
    use HasUuidAndSync, Auditable;

    protected $fillable = [
        'id',
        'name',
        'code',
        'sync_status',
        'last_modified_at',
    ];

    protected $casts = [
        'last_modified_at' => 'datetime',
    ];

    public function counties(): HasMany
    {
        return $this->hasMany(County::class);
    }
}
