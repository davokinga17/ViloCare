<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A patient moving from one facility to another keeps the SAME patient row (and ART number); this table is
     * the hand-over record: pending until the destination accepts, or cancelled by the sender. One patient can
     * have many transfers over time. Synced like other clinical tables.
     */
    public function up(): void
    {
        Schema::create('patient_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->uuid('from_facility_id');
            $table->uuid('to_facility_id');
            $table->date('transfer_date');
            $table->string('letter_reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('pending'); // pending, accepted, cancelled
            $table->uuid('requested_by')->nullable();
            $table->uuid('accepted_by')->nullable();
            $table->timestamp('accepted_at')->nullable();

            // Sync metadata
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->onDelete('cascade');
            $table->foreign('from_facility_id')->references('id')->on('facilities');
            $table->foreign('to_facility_id')->references('id')->on('facilities');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('accepted_by')->references('id')->on('users')->onDelete('set null');

            $table->index('patient_id');
            $table->index(['to_facility_id', 'status']);
            $table->index(['from_facility_id', 'status']);
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_transfers');
    }
};
