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
        Schema::create('sample_collections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sample_code')->unique(); // e.g. SMP-2026-0001
            $table->uuid('patient_id');
            $table->date('collection_date');
            $table->string('sample_type')->default('Plasma'); // Plasma, DBS, Whole Blood
            $table->uuid('collector_id')->nullable(); // User who collected
            $table->string('collector_name')->nullable();
            $table->string('health_facility_code')->nullable(); // Facility code e.g. FAC-1001
            $table->uuid('facility_id')->nullable();
            $table->string('status')->default('collected'); // collected, in_transit, received_at_lab, rejected, processed
            $table->date('sample_reception_date')->nullable();
            $table->uuid('received_by')->nullable(); // Lab tech who received
            $table->text('notes')->nullable();

            // Sync metadata
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            // Foreign keys & Indexes
            $table->foreign('patient_id')->references('id')->on('patients')->onDelete('cascade');
            $table->foreign('collector_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('received_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('facility_id')->references('id')->on('facilities')->onDelete('set null');

            $table->index('patient_id');
            $table->index('sample_code');
            $table->index('status');
            $table->index('collection_date');
            $table->index('sample_reception_date');
            $table->index('sync_status');
        });

        Schema::create('sample_rejections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sample_collection_id'); // Foreign key to specific sample_collection
            $table->date('rejection_date');
            $table->string('reason'); // e.g. Hemolyzed specimen, Insufficient volume, Clotted blood, etc.
            $table->text('action_taken'); // e.g. Discarded specimen, requested immediate redraw
            $table->text('corrective_action')->nullable(); // e.g. Phlebotomist re-training
            $table->uuid('rejected_by')->nullable(); // Staff who rejected

            // Sync metadata
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            // Foreign keys & Indexes
            $table->foreign('sample_collection_id')->references('id')->on('sample_collections')->onDelete('cascade');
            $table->foreign('rejected_by')->references('id')->on('users')->onDelete('set null');

            $table->index('sample_collection_id');
            $table->index('rejection_date');
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sample_rejections');
        Schema::dropIfExists('sample_collections');
    }
};
