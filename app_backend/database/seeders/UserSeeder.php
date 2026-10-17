<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // These accounts have publicly known passwords. Never create them on a real installation;
        // the first administrator is created with php artisan vilocare:first-admin.
        if (app()->environment('production')) {
            $this->command?->warn('UserSeeder skipped: demo accounts are never created when APP_ENV=production.');

            return;
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@vilocare.org'],
            [
                'id' => '16b2d3c2-8d5a-45cd-8465-0862aced7a4a',
                'name' => 'System Administrator',
                'phone' => '+211910000001',
                'role' => UserRole::ADMINISTRATOR,
                'password' => Hash::make('Password@123'),
                'cached_password_hash' => Hash::make('Password@123'),
                'must_change_password' => false,
                'is_active' => true,
                'sync_status' => 'synced',
                'last_modified_at' => now(),
            ]
        );

        $clinician = User::firstOrCreate(
            ['email' => 'clinician@vilocare.org'],
            [
                'id' => 'd4bf772f-e350-4ac8-a8ec-d4f7839051fb',
                'name' => 'Dr. Mercy Achieng (Clinician)',
                'phone' => '+211910000002',
                'role' => UserRole::CLINICIAN,
                'password' => Hash::make('Password@123'),
                'cached_password_hash' => Hash::make('Password@123'),
                'must_change_password' => false,
                'is_active' => true,
                'created_by' => $admin->id,
                'sync_status' => 'synced',
                'last_modified_at' => now(),
            ]
        );

        $labTech = User::firstOrCreate(
            ['email' => 'labtech@vilocare.org'],
            [
                'id' => 'a07534c6-ec2b-43ae-9f07-3bc4b2941dc4',
                'name' => 'Brian Kiprono (Lab Technician)',
                'phone' => '+211910000003',
                'role' => UserRole::LAB_TECHNICIAN,
                'password' => Hash::make('Password@123'),
                'cached_password_hash' => Hash::make('Password@123'),
                'must_change_password' => false,
                'is_active' => true,
                'created_by' => $clinician->id,
                'sync_status' => 'synced',
                'last_modified_at' => now(),
            ]
        );

        $dataClerk = User::firstOrCreate(
            ['email' => 'dataclerk@vilocare.org'],
            [
                'id' => '6bcaf037-e984-4160-be4b-328dc53f42c0',
                'name' => 'Sarah Wambui (Data Clerk)',
                'phone' => '+211910000004',
                'role' => UserRole::DATA_CLERK,
                'password' => Hash::make('Password@123'),
                'cached_password_hash' => Hash::make('Password@123'),
                'must_change_password' => false,
                'is_active' => true,
                'created_by' => $clinician->id,
                'sync_status' => 'synced',
                'last_modified_at' => now(),
            ]
        );

        // Account with temporary password requiring change on first login
        User::firstOrCreate(
            ['email' => 'newstaff@vilocare.org'],
            [
                'id' => '6eba327b-0338-4c8f-9c31-f1b284f0b159',
                'name' => 'David Omondi (New Intake Clerk)',
                'phone' => '+211910000005',
                'role' => UserRole::DATA_CLERK,
                'password' => Hash::make('TempPass@2026'),
                'cached_password_hash' => Hash::make('TempPass@2026'),
                'must_change_password' => true,
                'is_active' => true,
                'created_by' => $clinician->id,
                'sync_status' => 'synced',
                'last_modified_at' => now(),
            ]
        );
    }
}
