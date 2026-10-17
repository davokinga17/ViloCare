<?php

namespace Database\Seeders;

use App\Support\TestingLaboratoryCatalog;
use Illuminate\Database\Seeder;

class TestingLaboratorySeeder extends Seeder
{
    /** Idempotent: adds missing laboratories and fills missing facility links. Run after LocationSeeder. */
    public function run(): void
    {
        if (TestingLaboratoryCatalog::tableExists()) {
            TestingLaboratoryCatalog::sync();
        }
    }
}
