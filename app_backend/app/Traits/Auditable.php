<?php

namespace App\Traits;

use App\Models\AuditLog;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->recordAudit('created', null, $model->getAuditableAttributes());
        });

        static::updated(function ($model) {
            $old = array_intersect_key($model->getOriginal(), $model->getDirty());
            $new = $model->getDirty();

            // Filter out hidden/sensitive attributes
            $hidden = array_flip($model->getHiddenAuditFields());
            $old = array_diff_key($old, $hidden);
            $new = array_diff_key($new, $hidden);

            if (!empty($new)) {
                $model->recordAudit('updated', $old, $new);
            }
        });

        static::deleted(function ($model) {
            $model->recordAudit('deleted', $model->getAuditableAttributes(), null);
        });
    }

    public function recordAudit(string $action, ?array $oldValues = null, ?array $newValues = null): void
    {
        try {
            AuditLog::log(
                action: $action,
                tableName: $this->getTable(),
                recordId: (string) $this->getKey(),
                oldValues: $oldValues,
                newValues: $newValues,
                userId: auth()->id()
            );
        } catch (\Throwable $e) {
            // Silently handle if audit table is not yet migrated or during bulk seeding
            report($e);
        }
    }

    public function getAuditableAttributes(): array
    {
        $attributes = $this->attributesToArray();
        $hidden = array_flip($this->getHiddenAuditFields());
        return array_diff_key($attributes, $hidden);
    }

    public function getHiddenAuditFields(): array
    {
        return ['password', 'remember_token', 'cached_password_hash'];
    }
}
