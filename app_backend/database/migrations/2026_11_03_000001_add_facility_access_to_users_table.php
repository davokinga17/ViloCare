<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facility-based access: each non-administrator works for one facility, or (lab technicians only)
     * for a testing laboratory such as the National Public Health Laboratory. Existing users stay null
     * and must be assigned by an administrator. Both ids point at pull-only reference data.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('facility_id')->nullable()->after('role')->constrained('facilities')->nullOnDelete();
            $table->foreignUuid('testing_laboratory_id')->nullable()->after('facility_id')->constrained('testing_laboratories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('testing_laboratory_id');
            $table->dropConstrainedForeignId('facility_id');
        });
    }
};
