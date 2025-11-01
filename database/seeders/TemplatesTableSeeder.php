<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TemplatesTableSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('templates')->insert([
            [
                'id' => 1,
                'category_id' => 6,
                'original_image_path' => 'generated/6902e5604dab3_1761797472_0.png',
                'svg_path' => 'svgs/6902e5670c88d_1761797479_0.svg',
                'dimensions' => '{"width":1024,"height":1024}',
                'printing_dimensions' => null,
                'prompt_used' => 'Create a high-quality, modern, vector-style design template suitable for printing in a restaurant setting. The imagery should represent culinary themes such as utensils, dishes, etc. The primary color scheme should comprise professional tones, such as shades of blues or greys, well-balanced with a vibrant secondary palette to underline the vibrancy and dynamics of the restaurant industry. Emphasis should be on clean lines and shapes, displaying a sense of modernity. The typography should be sleek yet readable for various age groups, suitable for a modern, professional business setting. Variation 1.',
                'created_at' => '2025-10-29 22:11:19',
                'updated_at' => '2025-10-29 22:11:19',
            ],
            [
                'id' => 2,
                'category_id' => 6,
                'original_image_path' => 'generated/6902e567b7d3b_1761797479_1.png',
                'svg_path' => 'svgs/6902e5776b832_1761797495_1.svg',
                'dimensions' => '{"width":1024,"height":1024}',
                'printing_dimensions' => null,
                'prompt_used' => 'Generate a high-quality, professional design template for a restaurant. The design should possess clean lines, embody a modern aesthetic, and adopt a vector-style illustration that is suitable for clear and precise printing. Employ a color palette that blends professionalism and hospitality. Additionally, the typography should be legible and elegant. This design request is for a second variation of the template.',
                'created_at' => '2025-10-29 22:11:36',
                'updated_at' => '2025-10-29 22:11:36',
            ],
            [
                'id' => 3,
                'category_id' => 7,
                'original_image_path' => 'generated/6902f26a6779c_1761800810_0.png',
                'svg_path' => 'svgs/6902f27ad8e0b_1761800826_0.svg',
                'dimensions' => '{"width":1024,"height":1024}',
                'printing_dimensions' => '{"width":210,"height":297,"unit":"mm"}',
                'prompt_used' => 'Craft an image that represents a professional, high-quality design template intended for business usage. This design should encapsulate a modern aesthetic with a focus on vector-style graphics that are ideal for print. Important elements to consider are the utilization of professional shades and effective typography. This will represent the first variation of this design concept.',
                'created_at' => '2025-10-29 23:07:07',
                'updated_at' => '2025-10-29 23:07:07',
            ],
            [
                'id' => 4,
                'category_id' => 7,
                'original_image_path' => 'generated/6902f71623d72_1761802006_0.png',
                'svg_path' => 'svgs/6902f71c7e133_1761802012_0.svg',
                'dimensions' => '{"width":1024,"height":1024}',
                'printing_dimensions' => null,
                'prompt_used' => 'Generate a high-quality, professional design template meant for a business context. This should be minimalistic and modern in appeal, created in a crisp, vector style that is suitable for clean printing jobs. The colors employed should be professional, ideally in cooler tones to emphasize a corporate feel. The typography should possess a refined, contemporary aesthetic that complements the design. This is to be the first variation of the design.',
                'created_at' => '2025-10-29 23:26:53',
                'updated_at' => '2025-10-29 23:26:53',
            ],
            [
                'id' => 5,
                'category_id' => 6,
                'original_image_path' => 'generated/690305f4203d1_1761805812_0.png',
                'svg_path' => 'svgs/690305f7b92ed_1761805815_0.svg',
                'dimensions' => '{"width":1024,"height":1024}',
                'printing_dimensions' => null,
                'prompt_used' => 'Generate a professional, high-quality design template suitable for a restaurant. The design should boast a clean, modern aesthetic with vector-style visual elements, tailored for printing. Focus on implementing professional colors and appropriate typography. This is the first out of multiple variations of templates.',
                'created_at' => '2025-10-30 00:30:16',
                'updated_at' => '2025-10-30 00:30:16',
            ],
        ]);
    }
}


