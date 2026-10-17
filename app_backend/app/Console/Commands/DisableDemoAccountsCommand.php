<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Finds the demo accounts that older installations created from UserSeeder (shared, publicly known passwords)
 * and deactivates them. Matching is by e-mail address only; no password is read, checked or printed.
 * Dry run unless --apply is given. The change syncs like any other user edit, so one run on the central
 * server (or on one clinic) reaches the rest.
 */
class DisableDemoAccountsCommand extends Command
{
    public const DEMO_EMAILS = [
        'admin@vilocare.org',
        'clinician@vilocare.org',
        'labtech@vilocare.org',
        'dataclerk@vilocare.org',
        'newstaff@vilocare.org',
    ];

    protected $signature = 'vilocare:disable-demo-accounts {--apply : Actually deactivate the accounts (default is a dry run)}';

    protected $description = 'Deactivate the demo accounts created by the old seeder (dry run by default)';

    public function handle(): int
    {
        $accounts = User::whereIn('email', self::DEMO_EMAILS)->orderBy('email')->get();

        if ($accounts->isEmpty()) {
            $this->info('No demo accounts found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Email', 'Role', 'Active'],
            $accounts->map(fn (User $u) => [$u->email, $u->role instanceof UserRole ? $u->role->value : (string) $u->role, $u->is_active ? 'yes' : 'no'])->all()
        );

        $toDisable = $accounts->where('is_active', true);
        if ($toDisable->isEmpty()) {
            $this->info('All demo accounts are already inactive.');

            return self::SUCCESS;
        }

        $otherAdmins = User::where('role', UserRole::ADMINISTRATOR->value)
            ->where('is_active', true)
            ->whereNotIn('email', self::DEMO_EMAILS)
            ->count();
        if ($otherAdmins === 0) {
            $this->error('Refusing: no other active administrator exists, so disabling these would lock everyone out. Create a real administrator first.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->warn("Dry run: {$toDisable->count()} account(s) would be deactivated. Re-run with --apply.");

            return self::SUCCESS;
        }

        foreach ($toDisable as $user) {
            $user->is_active = false;
            $user->save();
        }

        $this->info("Deactivated {$toDisable->count()} demo account(s). They will reach other databases on the next sync.");

        return self::SUCCESS;
    }
}
