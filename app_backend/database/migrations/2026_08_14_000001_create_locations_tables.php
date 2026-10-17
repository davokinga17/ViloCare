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
        Schema::create('states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('counties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('state_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            $table->foreign('state_id')->references('id')->on('states')->onDelete('cascade');
        });

        Schema::create('facilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('county_id');
            $table->string('name');
            $table->string('code')->unique();
            $table->string('facility_level')->nullable(); // e.g. Level 4, Sub-County Hospital, ART Clinic
            $table->string('sync_status')->default('synced');
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            $table->foreign('county_id')->references('id')->on('counties')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('counties');
        Schema::dropIfExists('states');
    }
};
