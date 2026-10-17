<?php

namespace App\Enums;

enum SyncStatus: string
{
    case PENDING = 'pending';
    case SYNCED = 'synced';
    case CONFLICT = 'conflict';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Sync',
            self::SYNCED => 'Synced',
            self::CONFLICT => 'Conflict',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
