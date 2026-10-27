<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Central server only in practice: one row per clinic computer, updated by the heartbeat every sync request carries.
     * Intentionally NOT a synced model.
     */
    public function up(): void
    {
        Schema::create('clinic_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 120)->unique();
            $table->string('app_version', 40)->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_devices');
    }
};
