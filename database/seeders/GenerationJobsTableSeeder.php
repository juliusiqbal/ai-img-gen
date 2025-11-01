<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GenerationJobsTableSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('generation_jobs')->insert([
            [
                'id' => 6,
                'category_id' => 6,
                'status' => 'completed',
                'request_data' => '{"category_name":"restaurant","template_count":"2"}',
                'error_message' => null,
                'created_at' => '2025-10-29 22:10:39',
                'updated_at' => '2025-10-29 22:11:36',
            ],
            [
                'id' => 7,
                'category_id' => 7,
                'status' => 'completed',
                'request_data' => '{"category_name":"business","standard_size":"A4","template_count":"1"}',
                'error_message' => null,
                'created_at' => '2025-10-29 23:06:34',
                'updated_at' => '2025-10-29 23:07:07',
            ],
            [
                'id' => 8,
                'category_id' => 7,
                'status' => 'completed',
                'request_data' => '{"category_id":"7","template_count":"1"}',
                'error_message' => null,
                'created_at' => '2025-10-29 23:26:32',
                'updated_at' => '2025-10-29 23:26:53',
            ],
            [
                'id' => 9,
                'category_id' => 6,
                'status' => 'completed',
                'request_data' => '{"category_id":"6","template_count":"1","image":{}}',
                'error_message' => null,
                'created_at' => '2025-10-30 00:29:57',
                'updated_at' => '2025-10-30 00:30:16',
            ],
        ]);
    }
}


