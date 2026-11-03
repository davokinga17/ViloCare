<?php

namespace App\Models;

use App\Services\AccessScope;
use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Builder;
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

    /** Only locations the logged-in user may choose or filter by (see AccessScope). */
    public function scopeAllowed(Builder $query): Builder
    {
        $scope = AccessScope::current();

        return $scope->isAll() ? $query : $query->whereIn('id', $scope->stateIds());
    }

    protected $casts = [
        'last_modified_at' => 'datetime',
    ];

    public function counties(): HasMany
    {
        return $this->hasMany(County::class);
    }
}
