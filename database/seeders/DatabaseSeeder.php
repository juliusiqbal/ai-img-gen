<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed core reference and sample data from image_generator.sql
        $this->call([
            CategoriesTableSeeder::class,
            GenerationJobsTableSeeder::class,
            TemplatesTableSeeder::class,
        ]);
    }
}
