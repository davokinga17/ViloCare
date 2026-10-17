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
        Schema::create('eac_episodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->uuid('viral_load_result_id'); // Triggering high-VL result
            $table->uuid('repeat_viral_load_result_id')->nullable(); // Repeat test post-EAC
            $table->date('repeat_vl_due_date')->nullable();
            $table->string('status')->default('active'); // active, sessions_completed, repeat_vl_done, resolved_suppressed, flagged_for_regimen_switch
            
            // Sync metadata
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients')->onDelete('cascade');
            $table->foreign('viral_load_result_id')->references('id')->on('viral_load_results')->onDelete('cascade');
            $table->index('patient_id');
            $table->index('status');
            $table->index('sync_status');
        });

        Schema::create('eac_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('eac_episode_id');
            $table->uuid('patient_id');
            $table->unsignedSmallInteger('session_number'); // 1, 2, 3
            $table->date('session_date');
            $table->uuid('counselor_id')->nullable();
            $table->text('barriers')->nullable();
            $table->text('action_plan')->nullable();
            $table->text('notes')->nullable();
            $table->string('completion_status')->default('pending'); // pending, completed, missed
            $table->date('next_session_date')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Sync metadata
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            $table->foreign('eac_episode_id')->references('id')->on('eac_episodes')->onDelete('cascade');
            $table->foreign('patient_id')->references('id')->on('patients')->onDelete('cascade');
            $table->foreign('counselor_id')->references('id')->on('users')->onDelete('set null');

            $table->index(['eac_episode_id', 'session_number']);
            $table->index('patient_id');
            $table->index('session_date');
            $table->index('completion_status');
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eac_sessions');
        Schema::dropIfExists('eac_episodes');
    }
};
