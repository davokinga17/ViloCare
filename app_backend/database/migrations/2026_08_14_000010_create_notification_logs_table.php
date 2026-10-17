<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id')->nullable();
            $table->string('recipient_phone');
            $table->string('category'); // result_ready, due_date_reminder, eac_session_reminder, appointment_reminder, custom
            $table->text('message_body');
            $table->string('status')->default('queued'); // queued, sent, failed, delivered
            $table->string('provider')->default('africastalking');
            $table->string('provider_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();

            // Sync metadata
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            // Foreign keys & Indexes
            $table->foreign('patient_id')->references('id')->on('patients')->onDelete('set null');
            $table->index('patient_id');
            $table->index('recipient_phone');
            $table->index('category');
            $table->index('status');
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
