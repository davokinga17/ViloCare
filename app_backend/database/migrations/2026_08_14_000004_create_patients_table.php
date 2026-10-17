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
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('art_number')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('sex'); // male, female
            $table->unsignedSmallInteger('age'); // single reliable numeric field
            $table->date('dob')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->date('art_start_date');
            $table->boolean('is_pregnant')->default(false);
            $table->boolean('is_breastfeeding')->default(false);
            
            // Location Foreign Keys to lookup tables
            $table->uuid('state_id');
            $table->uuid('county_id');
            $table->uuid('facility_id');

            $table->string('status')->default('active'); // active, transferred_out, lost_to_followup, deceased
            $table->uuid('created_by')->nullable();

            // Sync metadata
            $table->string('sync_status')->default('synced'); // pending, synced, conflict
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            // Indexes and Foreign Keys
            $table->foreign('state_id')->references('id')->on('states')->onDelete('restrict');
            $table->foreign('county_id')->references('id')->on('counties')->onDelete('restrict');
            $table->foreign('facility_id')->references('id')->on('facilities')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');

            $table->index('art_number');
            $table->index(['first_name', 'last_name']);
            $table->index('age');
            $table->index('sex');
            $table->index('is_pregnant');
            $table->index('is_breastfeeding');
            $table->index('status');
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
