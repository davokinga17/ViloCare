<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Demo users and demo patients are for development and tests only.
        if (app()->environment('production')) {
            $this->call([LocationSeeder::class]);

            return;
        }

        $this->call([
            LocationSeeder::class,
            UserSeeder::class,
            PatientSeeder::class,
        ]);
    }
}
