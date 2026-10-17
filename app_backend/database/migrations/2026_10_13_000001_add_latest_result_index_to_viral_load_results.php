<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Latest viral load per patient" (dashboard, VL list, reports) asks for a newer result of the same patient,
 * ordered by sample_date, then created_at, then id. This composite index matches that ordering, so the lookup
 * stays inside the index instead of fetching and sorting every result of the patient.
 */
return new class extends Migration
{
    private const INDEX = 'viral_load_results_latest_result_index';

    public function up(): void
    {
        if (! Schema::hasIndex('viral_load_results', self::INDEX)) {
            Schema::table('viral_load_results', function (Blueprint $table) {
                $table->index(['patient_id', 'sample_date', 'created_at', 'id'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('viral_load_results', self::INDEX)) {
            Schema::table('viral_load_results', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};