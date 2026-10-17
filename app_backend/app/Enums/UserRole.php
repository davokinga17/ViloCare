<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMINISTRATOR = 'administrator';
    case CLINICIAN = 'clinician';
    case LAB_TECHNICIAN = 'lab_technician';
    case DATA_CLERK = 'data_clerk';

    public function label(): string
    {
        return match ($this) {
            self::ADMINISTRATOR => 'Administrator',
            self::CLINICIAN => 'Clinician',
            self::LAB_TECHNICIAN => 'Lab Technician',
            self::DATA_CLERK => 'Data Clerk',
        };
    }

    public function canManageUserWithRole(UserRole $targetRole): bool
    {
        if ($this === self::ADMINISTRATOR) {
            return true;
        }

        if ($this === self::CLINICIAN) {
            return in_array($targetRole, [self::LAB_TECHNICIAN, self::DATA_CLERK], true);
        }

        return false;
    }

    /**
     * @return array<UserRole>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::ADMINISTRATOR => [
                self::ADMINISTRATOR,
                self::CLINICIAN,
                self::LAB_TECHNICIAN,
                self::DATA_CLERK,
            ],
            self::CLINICIAN => [
                self::LAB_TECHNICIAN,
                self::DATA_CLERK,
            ],
            default => [],
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
