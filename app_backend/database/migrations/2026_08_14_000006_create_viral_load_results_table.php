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
        Schema::create('viral_load_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->date('sample_date');
            $table->date('result_date');
            $table->unsignedInteger('result_value'); // Copies / ml (0 to millions)
            $table->boolean('is_target_not_detected')->default(false); // True if LDL / undetectable
            $table->boolean('is_suppressed')->default(true); // < 1,000 copies/ml = true; >= 1,000 = false
            $table->string('lab_name')->default('National Reference Lab');
            $table->string('sample_type')->default('Plasma'); // Plasma, DBS, Whole Blood
            $table->uuid('requesting_clinician_id')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->text('notes')->nullable();
            
            // Algorithm & Repeat flags
            $table->boolean('is_post_eac_repeat')->default(false);
            $table->uuid('eac_episode_id')->nullable();
            $table->date('next_due_date')->nullable();
            $table->string('algorithm_reason')->nullable();
            $table->boolean('flagged_for_clinical_review')->default(false); // Post-EAC persistent high VL flag

            // Sync metadata
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            // Foreign keys & Indexes
            $table->foreign('patient_id')->references('id')->on('patients')->onDelete('cascade');
            $table->foreign('requesting_clinician_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');

            $table->index('patient_id');
            $table->index('sample_date');
            $table->index('result_date');
            $table->index('is_suppressed');
            $table->index('next_due_date');
            $table->index('flagged_for_clinical_review');
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('viral_load_results');
    }
};
