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
        Schema::create('regimen_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->string('regimen_name');
            $table->string('regimen_line')->default('1st Line'); // 1st Line, 2nd Line, 3rd Line
            $table->date('start_date');
            $table->date('end_date')->nullable(); // NULL indicates currently active regimen
            $table->text('reason_for_change')->nullable();
            $table->uuid('recorded_by')->nullable();

            // Sync metadata
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            // Foreign keys & Indexes
            $table->foreign('patient_id')->references('id')->on('patients')->onDelete('cascade');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');

            $table->index('patient_id');
            $table->index('start_date');
            $table->index('end_date');
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regimen_histories');
    }
};
