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
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->date('appointment_date');
            $table->string('appointment_time')->nullable(); // e.g. 09:00 AM, Morning, Afternoon
            $table->string('reason'); // e.g. Routine Clinical Review, Viral Load Sample Collection, EAC Session, Drug Refill
            $table->string('status')->default('scheduled'); // scheduled, completed, missed, rescheduled, cancelled
            $table->uuid('scheduled_by')->nullable();
            $table->timestamp('attended_at')->nullable();
            $table->uuid('attended_by')->nullable();
            $table->text('notes')->nullable();

            // Sync metadata
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            // Foreign keys & Indexes
            $table->foreign('patient_id')->references('id')->on('patients')->onDelete('cascade');
            $table->foreign('scheduled_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('attended_by')->references('id')->on('users')->onDelete('set null');

            $table->index('patient_id');
            $table->index('appointment_date');
            $table->index('status');
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
