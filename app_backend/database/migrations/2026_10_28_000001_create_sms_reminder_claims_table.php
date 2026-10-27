<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per (patient, due date) reminder. The unique key is the atomic duplicate guard: whoever inserts the
     * row first may send; every concurrent run loses the insert and sends nothing.
     * Intentionally NOT a synced model: the guard lives on the computer that sends SMS.
     */
    public function up(): void
    {
        Schema::create('sms_reminder_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('patient_id');
            $table->date('due_date');
            $table->timestamp('claimed_at')->nullable();

            $table->unique(['patient_id', 'due_date'], 'sms_reminder_claims_patient_due_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_reminder_claims');
    }
};
