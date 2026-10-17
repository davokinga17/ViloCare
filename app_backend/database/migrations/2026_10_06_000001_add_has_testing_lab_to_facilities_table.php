<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facilities that operate a testing laboratory (matched by exact name).
     */
    private array $labFacilities = [
        'Kapoeta Civil Hospital',
        'Narus PHCC',
        'St. Theresa Mission Hospital Isohe',
        'Torit State Hospital',
        'Magwi PHCC',
        'Nimule Hospital',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('facilities', 'has_testing_lab')) {
            Schema::table('facilities', function (Blueprint $table) {
                $table->boolean('has_testing_lab')->default(false)->after('facility_level');
            });
        }

        // Flag existing rows and mark them pending so the change syncs to the cloud.
        // Facilities missing locally are created by LocationSeeder (which also sets the flag).
        DB::table('facilities')->whereIn('name', $this->labFacilities)->update([
            'has_testing_lab' => true,
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('facilities', 'has_testing_lab')) {
            Schema::table('facilities', function (Blueprint $table) {
                $table->dropColumn('has_testing_lab');
            });
        }
    }
};
