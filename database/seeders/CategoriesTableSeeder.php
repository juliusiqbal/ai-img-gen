<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoriesTableSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('categories')->insert([
            [
                'id' => 6,
                'name' => 'restaurant',
                'description' => null,
                'created_at' => '2025-10-29 22:10:39',
                'updated_at' => '2025-10-29 22:10:39',
            ],
            [
                'id' => 7,
                'name' => 'business',
                'description' => null,
                'created_at' => '2025-10-29 23:06:34',
                'updated_at' => '2025-10-29 23:06:34',
            ],
        ]);
    }
}


