<?php

namespace App\Traits;

use App\Enums\SyncStatus;
use Illuminate\Support\Str;

trait HasUuidAndSync
{
    public static function bootHasUuidAndSync(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'sync_status') && empty($model->sync_status)) {
                $model->sync_status = SyncStatus::PENDING->value;
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'last_modified_at') && empty($model->last_modified_at)) {
                $model->last_modified_at = now();
            }
        });

        static::updating(function ($model) {
            if (\Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'last_modified_at')) {
                $model->last_modified_at = now();
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn($model->getTable(), 'sync_status') && !$model->isDirty('sync_status')) {
                $model->sync_status = SyncStatus::PENDING->value;
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public function scopePendingSync($query)
    {
        return $query->where('sync_status', SyncStatus::PENDING->value);
    }

    public function scopeSynced($query)
    {
        return $query->where('sync_status', SyncStatus::SYNCED->value);
    }

    public function scopeConflicts($query)
    {
        return $query->where('sync_status', SyncStatus::CONFLICT->value);
    }

    public function markAsSynced(): bool
    {
        $this->sync_status = SyncStatus::SYNCED->value;
        return $this->saveQuietly();
    }

    public function markAsConflict(): bool
    {
        $this->sync_status = SyncStatus::CONFLICT->value;
        return $this->saveQuietly();
    }
}
