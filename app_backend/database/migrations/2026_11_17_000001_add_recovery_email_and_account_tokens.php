<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Self-service password reset. Many staff log in with a placeholder address that is not a real mailbox, so
     * a separate recovery email is kept (synced like the other user fields, but only sent to apps that know it).
     * The reset / verification tokens live only on the central server and are never synced.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('recovery_email')->nullable()->after('email');
            $table->timestamp('recovery_email_verified_at')->nullable()->after('recovery_email');

            // One account per recovery address. Addresses are stored lower-case (User model), so this is also
            // case-insensitive; several users may have none (NULLs do not collide). A plain index, not an
            // expression index: SQLite table rebuilds in later migrations cannot handle those.
            $table->unique('recovery_email', 'users_recovery_email_unique');
        });

        Schema::create('account_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id')->index();
            $table->string('purpose', 20); // reset | verify_email
            $table->string('token_hash', 64)->unique(); // sha256 of the token: the token itself is never stored
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Existing user rows are unchanged on the server, so the pull cursor would skip them: re-pull once.
        (new \App\Services\SyncStateStore())->requestFullRepullIfPulledBefore('users: recovery_email, recovery_email_verified_at added');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_tokens');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_recovery_email_unique');
            $table->dropColumn(['recovery_email', 'recovery_email_verified_at']);
        });
    }
};
