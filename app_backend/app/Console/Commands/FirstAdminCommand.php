<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Creates the very first administrator on a fresh clinic computer. It is deliberately a console command
 * (driven by the desktop shell's first-run flow), not a web route, so it cannot be reached later through
 * the browser. It refuses to run unless the users table is empty, and never seeds default passwords.
 *
 * Input is a JSON object on STDIN ({"name","email","password","password_confirmation"}) so the password
 * never appears in a command line or environment. Output is one line of JSON.
 */
class FirstAdminCommand extends Command
{
    public const EXIT_USERS_EXIST = 10;

    public const MIN_PASSWORD_LENGTH = 10;

    protected $signature = 'vilocare:first-admin {--check : Only report whether the users table is empty (exit 0) or not (exit 10)}';

    protected $description = 'Create the first administrator on this computer (only works while there are no users)';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->line(json_encode(['ok' => false, 'reason' => 'users_exist']));

            return self::EXIT_USERS_EXIST;
        }

        if ($this->option('check')) {
            $this->line(json_encode(['ok' => true, 'reason' => 'no_users']));

            return self::SUCCESS;
        }

        // 'first-admin.input' lets tests supply the JSON without a real STDIN.
        $raw = $this->laravel->bound('first-admin.input')
            ? $this->laravel->make('first-admin.input')
            : stream_get_contents(STDIN);
        $input = json_decode((string) $raw, true);
        if (! is_array($input)) {
            $this->line(json_encode(['ok' => false, 'errors' => ['Missing administrator details.']]));

            return self::FAILURE;
        }

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH, 'max:255', 'confirmed'],
        ], [
            'password.min' => 'The password must be at least '.self::MIN_PASSWORD_LENGTH.' characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        if ($validator->fails()) {
            $this->line(json_encode(['ok' => false, 'errors' => $validator->errors()->all()]));

            return self::FAILURE;
        }

        $data = $validator->validated();

        // last_modified_at is deliberately old: if the central server already has this email, its (newer)
        // record wins the Last-Write-Wins comparison at the first sync instead of being overwritten by
        // this brand-new local copy. If the central server has no such email, this account is added there.
        User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role' => UserRole::ADMINISTRATOR,
            'password' => $data['password'],
            'cached_password_hash' => Hash::make($data['password']),
            'must_change_password' => false,
            'is_active' => true,
            'last_modified_at' => Carbon::create(2000, 1, 1),
        ]);

        $this->line(json_encode(['ok' => true]));

        return self::SUCCESS;
    }
}
