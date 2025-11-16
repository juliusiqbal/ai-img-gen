<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OpenAIService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
    }

    /**
     * Generate image using GPT Image 1
     */
    public function generateImage(string $prompt, string $size = '1024x1536'): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('OpenAI API key is not configured');
        }

        try {
            Log::info('Sending prompt to GPT Image 1', [
                'prompt_length' => strlen($prompt),
                'prompt' => $prompt,
            ]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->retry(2, 500)->timeout(120)->post("{$this->baseUrl}/images/generations", [
                'model' => 'gpt-image-1',
                'prompt' => $prompt,
                'n' => 1,
                'size' => $size,
                'quality' => 'low',
            ]);

            if ($response->failed()) {
                $errorBody = $response->json();
                $errorMessage = 'OpenAI API request failed';
                
                if (isset($errorBody['error']['message'])) {
                    $errorMessage = $errorBody['error']['message'];
                } else {
                    $errorMessage .= ': ' . $response->body();
                }
                
                Log::error('OpenAI API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'error_code' => $errorBody['error']['code'] ?? null,
                ]);
                
                throw new \Exception($errorMessage);
            }

            $data = $response->json();
            $images = [];

            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $item) {
                    if (isset($item['b64_json'])) {
                        $base64Data = $item['b64_json'];
                        $imageData = base64_decode($base64Data);
                        
                        $destinationPath = 'generated/' . uniqid() . '_' . time() . '.png';
                        $fullPath = storage_path('app/public/' . $destinationPath);
                        $dir = dirname($fullPath);

                        if (!is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }

                        file_put_contents($fullPath, $imageData);
                        
                        $images[] = [
                            'path' => $destinationPath,
                            'revised_prompt' => $item['revised_prompt'] ?? $prompt,
                        ];
                    } elseif (isset($item['url'])) {
                        $images[] = [
                            'url' => $item['url'],
                            'revised_prompt' => $item['revised_prompt'] ?? $prompt,
                        ];
                    }
                }
            }

            return $images;
        } catch (\Exception $e) {
            Log::error('Image generation error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Download image from URL and save to storage
     */
    public function downloadImage(string $url, string $destinationPath): ?string
    {
        try {
            $response = Http::retry(2, 500)->timeout(120)->get($url);

            if ($response->successful()) {
                $fullPath = storage_path('app/public/' . $destinationPath);
                $dir = dirname($fullPath);

                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                file_put_contents($fullPath, $response->body());
                return $destinationPath;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Image download error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Analyze multiple images using GPT-4 Vision API
     */
    public function analyzeMultipleImagesWithVision(array $imagePaths, string $category): ?string
    {
        if (empty($this->apiKey)) {
            Log::warning('OpenAI API key not configured for image analysis');
            return null;
        }

        if (empty($imagePaths)) {
            return null;
        }

        try {
            $content = [
                [
                    'type' => 'text',
                    'text' => "Analyze these images and provide a detailed description focusing on: 1) Main visual elements and objects in each image, 2) Color schemes and palettes, 3) Composition and layout of each image, 4) How these images can be combined into an organized, attractive template layout. This description will be used to generate a PHOTOREALISTIC design template for the '{$category}' category. IMPORTANT: The generated output must be photorealistic. Describe how to incorporate these actual images into a template layout with text overlays. Be specific about how to arrange these images in an attractive, organized template composition."
                ]
            ];

            foreach ($imagePaths as $imagePath) {
                $fullImagePath = storage_path('app/public/' . $imagePath);
                
                if (!file_exists($fullImagePath)) {
                    Log::error("Image file not found for analysis: {$fullImagePath}");
                    continue;
                }

                $imageData = file_get_contents($fullImagePath);
                $base64Image = base64_encode($imageData);
                
                $mimeType = mime_content_type($fullImagePath);
                if (!$mimeType) {
                    $extension = pathinfo($fullImagePath, PATHINFO_EXTENSION);
                    $mimeTypes = [
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'webp' => 'image/webp',
                    ];
                    $mimeType = $mimeTypes[strtolower($extension)] ?? 'image/jpeg';
                }

                $content[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$mimeType};base64,{$base64Image}"
                    ]
                ];
            }

            if (count($content) === 1) {
                return null;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->retry(2, 500)->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $content
                    ]
                ],
                'max_tokens' => 500,
            ]);

            if ($response->failed()) {
                $errorBody = $response->json();
                Log::error('GPT-4 Vision API error (multiple images)', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            if (isset($data['choices'][0]['message']['content'])) {
                return trim($data['choices'][0]['message']['content']);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Multiple image analysis error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Analyze image using GPT-4 Vision API
     */
    public function analyzeImageWithVision(string $imagePath, string $category): ?string
    {
        if (empty($this->apiKey)) {
            Log::warning('OpenAI API key not configured for image analysis');
            return null;
        }

        try {
            $fullImagePath = storage_path('app/public/' . $imagePath);
            
            if (!file_exists($fullImagePath)) {
                Log::error("Image file not found for analysis: {$fullImagePath}");
                return null;
            }

            $imageData = file_get_contents($fullImagePath);
            $base64Image = base64_encode($imageData);
            
            $mimeType = mime_content_type($fullImagePath);
            if (!$mimeType) {
                $extension = pathinfo($fullImagePath, PATHINFO_EXTENSION);
                $mimeTypes = [
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                ];
                $mimeType = $mimeTypes[strtolower($extension)] ?? 'image/jpeg';
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->retry(2, 500)->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "Analyze this image and provide a detailed description focusing on: 1) Main visual elements and objects, 2) Color scheme and palette, 3) Style and design approach (note if it's photorealistic, illustrative, vector-style, or flat design), 4) Composition and layout, 5) Theme and mood. This description will be used to generate a PHOTOREALISTIC design template for the '{$category}' category. IMPORTANT: If the reference image is illustrative, vector-style, or flat design, note this but the generated output must be photorealistic. Be specific about visual elements that would be relevant for creating a realistic, photographic-style design in the {$category} context. Format your response as a concise description suitable for an AI image generation prompt, emphasizing realistic, photographic qualities."
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$base64Image}"
                                ]
                            ]
                        ]
                    ]
                ],
                'max_tokens' => 300,
            ]);

            if ($response->failed()) {
                $errorBody = $response->json();
                Log::error('GPT-4 Vision API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            if (isset($data['choices'][0]['message']['content'])) {
                return trim($data['choices'][0]['message']['content']);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Image analysis error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Refine prompt using GPT-4 for complex requests
     */
    private function refinePromptWithGPT4(string $category, ?string $imageDescription = null, int $variationIndex = 0, array $imagePaths = []): ?string
    {
        if (empty($this->apiKey)) {
            Log::warning('OpenAI API key not configured for prompt refinement');
            return null;
        }

        try {
            $systemPrompt = "You are an expert at creating optimized prompts for GPT Image 1 image generation. Your task is to analyze user requirements and create a highly detailed, structured prompt that will generate professional, PHOTOREALISTIC advertisement templates or design templates.

MANDATORY CONSTRAINTS - THESE MUST BE ENFORCED IN EVERY PROMPT:
1. TEXT CONSTRAINTS:
   - MANDATORY: All text must be perfectly horizontal - ZERO rotation, ZERO tilt, ZERO angle, ZERO diagonal text
   - MANDATORY: All text within the same text block or group must be IDENTICAL size - no size variations within blocks
   - MANDATORY: Text must be clearly readable with high contrast
   - MANDATORY: Use the EXACT text provided by the user - do not translate, modify, or paraphrase
   - MANDATORY: All text must be in the same language as provided in the user's details

2. COLOR CONSTRAINTS:
   - MANDATORY: Use ONLY solid colors - ABSOLUTELY NO gradients, NO color transitions, NO color blends, NO gradient fills
   - MANDATORY: Every color area must be a single, uniform color with no variations

3. PHOTOREALISTIC REQUIREMENTS:
   - CRITICAL: The output MUST be a REAL PHOTOGRAPH or PHOTOREALISTIC IMAGE. It must look like it was taken with a professional camera, not illustrated, drawn, or designed.
   - ABSOLUTELY FORBIDDEN: NO animation, NO cartoon, NO illustration, NO digital art, NO stylized design, NO vector graphics, NO drawings, NO artistic styles, NO abstract elements
   - MUST use: Actual camera photography appearance, real-world lighting conditions (natural sunlight, ambient lighting), authentic camera angles and perspectives, natural depth of field, realistic materials and textures

When creating the prompt, you must:
1. ALWAYS start with phrases like 'photorealistic photography', 'real photograph', 'professional photography', 'high-resolution photograph', or 'natural lighting photography'
2. Place MANDATORY constraints at the BEGINNING of the prompt, before any other instructions
3. Analyze the category and details to understand the exact requirements
4. Extract all key elements: destinations, locations, discounts, company names, text elements - use EXACT text as provided
5. Design an appropriate layout structure (split-screen, diagonal, overlay, etc.)
6. Specify exact text placement, sizes (same size within blocks), and colors (solid colors only)
7. Describe photorealistic visual elements using photography terminology (real landmarks, actual scenes, natural lighting, camera angles, depth of field)
8. Ensure all text elements are clearly visible and readable
9. Create a complete, optimized prompt ready for GPT Image 1

The output must be PHOTOREALISTIC PHOTOGRAPHY - absolutely NO vector graphics, illustrations, drawings, stylized designs, artistic styles, digital art, or graphic design. Use ONLY real photography style with:
- Actual camera photography appearance
- Real-world lighting conditions (natural sunlight, ambient lighting)
- Authentic camera angles and perspectives
- Natural depth of field and bokeh effects
- Realistic materials, textures, and surfaces
- Professional photography composition

ABSOLUTELY FORBIDDEN: Any mention of illustration, drawing, design, graphic, vector, artistic, stylized, cartoon, abstract, animation, or animated styles.

Return ONLY the optimized prompt text, nothing else.";

            $userPrompt = "Category: {$category}\n\n";
            
            if ($imageDescription) {
                if (!empty($imagePaths)) {
                    $userPrompt .= "Multiple images provided. Image analysis: {$imageDescription}\n\n";
                    $userPrompt .= "CRITICAL: The prompt must instruct GPT Image 1 to use the provided images and create an organized, attractive template layout incorporating these actual images with text overlays. ";
                } else {
                    $userPrompt .= "Reference image description: {$imageDescription}\n\n";
                }
            }
            
            $userPrompt .= "Create an optimized GPT Image 1 prompt for generating a REAL PHOTOGRAPHIC template. ";
            $userPrompt .= "CRITICAL: The prompt MUST start with MANDATORY constraints, then phrases emphasizing it's a real photograph (e.g., 'photorealistic photography', 'real photograph', 'professional photography'). ";
            $userPrompt .= "MANDATORY CONSTRAINTS TO INCLUDE AT THE BEGINNING: ";
            $userPrompt .= "1. All text must be perfectly horizontal - ZERO rotation, ZERO tilt, ZERO angle. ";
            $userPrompt .= "2. Use ONLY solid colors - ABSOLUTELY NO gradients, NO color transitions, NO color blends. ";
            $userPrompt .= "3. All text within the same block must be IDENTICAL size - no size variations within blocks. ";
            $userPrompt .= "4. Use the EXACT text provided - do not translate, modify, or paraphrase. ";
            $userPrompt .= "5. All text must be in the same language as provided in the details. ";
            $userPrompt .= "Include explicit layout instructions (split-screen for multiple destinations, diagonal elements, etc.), ";
            $userPrompt .= "text placement details (where each text element should appear, sizes must be same within blocks, colors must be solid only), ";
            $userPrompt .= "photorealistic photography descriptions of actual landmarks/scenes with real-world lighting and camera perspectives, ";
            $userPrompt .= "and professional advertisement layout structure using real photography composition. ";
            $userPrompt .= "Use photography terminology throughout (camera angles, depth of field, natural lighting, etc.). ";
            $userPrompt .= "ABSOLUTELY FORBIDDEN: NO animation, NO cartoon, NO illustration, NO digital art, NO stylized design, NO vector graphics. ";
            $userPrompt .= "The prompt must be complete, start with MANDATORY constraints and photorealistic photography emphasis, and be ready to use with GPT Image 1. ";
            $userPrompt .= "DO NOT mention illustration, drawing, design, animation, cartoon, or any artistic styles - ONLY real photography.";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->retry(2, 500)->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => $userPrompt,
                    ],
                ],
                'max_tokens' => 1000,
                'temperature' => 0.7,
            ]);

            if ($response->failed()) {
                $errorBody = $response->json();
                Log::error('GPT-4 prompt refinement error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            if (isset($data['choices'][0]['message']['content'])) {
                $refinedPrompt = trim($data['choices'][0]['message']['content']);
                
                Log::info('Prompt refined with GPT-4', [
                    'category' => $category,
                    'refined_length' => strlen($refinedPrompt),
                    'prompt' => $refinedPrompt,
                ]);
                
                return $refinedPrompt;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Prompt refinement error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate prompt from category and image context with enhanced constraints
     */
    public function generatePrompt(string $category, ?string $imageDescription = null, int $variationIndex = 0, array $imagePaths = [], bool $forceGPT4Refinement = false): string
    {
        if ($forceGPT4Refinement) {
            $refinedPrompt = $this->refinePromptWithGPT4($category, $imageDescription, $variationIndex, $imagePaths);
            if ($refinedPrompt) {
                return $refinedPrompt;
            }
            Log::warning('GPT-4 prompt refinement failed, using basic prompt generation');
        }
        
        $prompt = "MANDATORY CONSTRAINTS: ";
        $prompt .= "1. All text must be perfectly horizontal - ZERO rotation, ZERO tilt, ZERO angle, ZERO diagonal text. ";
        $prompt .= "2. Use ONLY solid colors - ABSOLUTELY NO gradients, NO color transitions, NO color blends, NO gradient fills anywhere. ";
        $prompt .= "3. All text within the same text block or group must be IDENTICAL size - no size variations within blocks. ";
        $prompt .= "4. Use the EXACT text provided by the user - do not translate, modify, or paraphrase. ";
        $prompt .= "5. All text must be in the same language as provided in the user's details. ";
        $prompt .= "6. Text must be clearly readable with high contrast. ";
        
        $prompt .= "Create a REAL PHOTOGRAPHIC design template for {$category}. This must be a photorealistic photograph, not an illustration, design, animation, or cartoon. ";
        $prompt .= "MUST be relevant to {$category} and include professional {$category} theme. ";
        $prompt .= "Include relevant visual elements for {$category}. ";
        $prompt .= "DO NOT include elements unrelated to {$category}. ";
        
        if ($imageDescription) {
            if (!empty($imagePaths)) {
                $prompt .= "CRITICAL: Use the provided images and create an organized, attractive template layout incorporating these actual images with the specified text elements. ";
                $prompt .= "The template should arrange these images in a professional, eye-catching layout. ";
                $prompt .= "Image analysis: {$imageDescription}. ";
                $prompt .= "Incorporate the actual provided images into the template - do not recreate or replace them. ";
                $prompt .= "The layout should be organized and attractive, with text overlays placed clearly on the images. ";
            } else {
                $prompt .= "The design should be inspired by these visual elements from the reference: {$imageDescription}. ";
                $prompt .= "CRITICAL: Even if the reference image is illustrative, stylized, or designed, the output MUST be a REAL PHOTOGRAPH. ";
                $prompt .= "Convert all visual elements into photorealistic photography with actual camera-captured appearance. ";
                $prompt .= "Maintain similar color scheme and composition, but render everything as a real photograph with natural lighting, realistic shadows, authentic textures, and professional camera photography. ";
                $prompt .= "Ensure all elements are relevant to {$category} and appear as if photographed with a professional camera in real life. ";
            }
        }
        
        $prompt .= "This MUST be a REAL PHOTOGRAPH taken with a professional camera. ";
        $prompt .= "Use photorealistic photography with real-world lighting conditions, authentic camera angles, natural depth of field, and professional photography composition. ";
        $prompt .= "The image must look like a high-resolution photograph captured with a DSLR or professional camera, not created digitally. ";
        $prompt .= "ABSOLUTELY FORBIDDEN: NO animation, NO cartoon, NO illustration, NO digital art, NO stylized design, NO vector graphics, NO drawings, NO artistic styles, NO graphic design, NO flat design, NO abstract elements. ";
        $prompt .= "Use natural lighting (sunlight, ambient light), realistic shadows with proper depth, authentic textures, and materials that look photographed. ";
        $prompt .= "Every element must appear as if it was photographed in real life with natural colors, realistic materials, and authentic appearances. ";
        $prompt .= "The final output must be indistinguishable from a real professional photograph. ";
        
        $prompt .= "Professional typography should be incorporated. ";
        $prompt .= "REINFORCE MANDATORY CONSTRAINTS: All text must be perfectly horizontal (ZERO rotation). Use ONLY solid colors (NO gradients). Text within the same block must be IDENTICAL size. ";
        $prompt .= "Text must be clearly readable with high contrast and in the same language as provided. ";
        $prompt .= "Ensure all visual elements are semantically consistent with the {$category} category - no mismatched or unrelated imagery.";
        
        $variationInstructions = $this->getVariationInstructions($variationIndex);
        if ($variationInstructions) {
            $prompt .= " " . $variationInstructions;
        }

        return $prompt;
    }


    /**
     * Get variation-specific instructions
     */
    private function getVariationInstructions(int $variationIndex): string
    {
        $instructions = [
            0 => "Focus on a bold, vibrant color scheme with strong visual impact, rendered in photorealistic style with natural lighting.",
            1 => "Use a different composition and layout approach, perhaps with more white space or alternative element arrangement, maintaining photorealistic quality.",
            2 => "Emphasize a different aspect of the category theme while maintaining strict relevance, with realistic photographic rendering.",
            3 => "Create a variation with different visual elements but keeping the same category context, all rendered in photorealistic style with natural textures and lighting.",
        ];

        return $instructions[$variationIndex] ?? '';
    }

    /**
     * Generate multiple variations with meaningful differences
     */
    public function generateVariations(string $category, ?string $imageDescription = null, int $count = 1, array $imagePaths = [], bool $forceGPT4Refinement = false): array
    {
        $images = [];

        for ($i = 0; $i < $count; $i++) {
            try {
                $variationPrompt = $this->generatePrompt($category, $imageDescription, $i, $imagePaths, $forceGPT4Refinement);
                
                $result = $this->generateImage($variationPrompt);

                if (!empty($result)) {
                    $images = array_merge($images, $result);
                }

                if ($i < $count - 1) {
                    sleep(1);
                }
            } catch (\Exception $e) {
                Log::error("Failed to generate variation " . ($i + 1) . ": " . $e->getMessage());
                continue;
            }
        }

        return $images;
    }

    /**
     * Generate 2-3 random text blocks related to category and keywords using GPT-4o
     */
    private function generateTextBlocks(string $categoryName, string $keywords): array
    {
        if (empty($this->apiKey)) {
            Log::warning('OpenAI API key not configured for text block generation');
            return [];
        }

        try {
            $numberOfBlocks = rand(2, 3);

            $systemPrompt = "You are a creative copywriter specializing in advertising and marketing text. Generate {$numberOfBlocks} short, compelling text blocks (headlines, taglines, or call-to-action phrases) that are relevant to the given category and keywords. Each text block should be concise (maximum 10 words), professional, and suitable for use in design templates.";

            $userPrompt = "Generate {$numberOfBlocks} text blocks for:\n";
            if ($categoryName) {
                $userPrompt .= "Category: {$categoryName}\n";
            }
            if ($keywords) {
                $userPrompt .= "Keywords: {$keywords}\n";
            }
            $userPrompt .= "\nReturn ONLY the text blocks, one per line, without numbering or bullets. Each text block should be on its own line. Make them relevant, engaging, and appropriate for the category and keywords provided.";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post("{$this->baseUrl}/chat/completions", [
                'model' => 'gpt-4o',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.9,
                'max_tokens' => 200,
            ]);

            if (!$response->successful()) {
                Log::error('GPT-4o text block generation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $responseData = $response->json();
            $content = $responseData['choices'][0]['message']['content'] ?? '';

            $textBlocks = [];
            $lines = explode("\n", trim($content));
            
            foreach ($lines as $line) {
                $line = trim($line);
                $line = preg_replace('/^[\d\.\)\-\*\•]\s*/', '', $line);
                $line = trim($line, '"\'');
                if (!empty($line) && strlen($line) > 3) {
                    $textBlocks[] = $line;
                }
            }

            $textBlocks = array_slice($textBlocks, 0, $numberOfBlocks);

            Log::info('Text blocks generated by GPT-4o', [
                'category' => $categoryName,
                'keywords' => $keywords,
                'count' => count($textBlocks),
                'text_blocks' => $textBlocks,
            ]);

            return $textBlocks;
        } catch (\Exception $e) {
            Log::error('Text block generation error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Generate structured prompts from design preferences using GPT-4
     */
    public function generateStructuredPrompts(array &$designPreferences): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('OpenAI API key is not configured');
        }

        try {
            $templateType = $designPreferences['template_type'] ?? 'poster';
            $keywords = $designPreferences['keywords'] ?? '';
            $fontFamily = $designPreferences['font_family'] ?? '';
            $fontSizes = $designPreferences['font_sizes'] ?? [];
            $imageStyles = ['realistic', 'professional', 'minimal', 'modern', 'classic'];
            $imageStyle = $imageStyles[array_rand($imageStyles)];
            $numberOfTemplates = $designPreferences['number_of_templates'] ?? 1;
            $categoryName = $designPreferences['category_name'] ?? '';

            $textBlocks = $this->generateTextBlocks($categoryName, $keywords);
            $designPreferences['text_blocks'] = $textBlocks;
            
            if (empty($fontSizes) && !empty($textBlocks)) {
                $fontSizes = array_fill(0, count($textBlocks), 'medium');
                $designPreferences['font_sizes'] = $fontSizes;
            }

            $systemPrompt = "You are a professional graphic design prompt engineer. Your task is to create highly detailed, structured prompts for GPT Image 1 to generate professional {$templateType} templates. 

CRITICAL CONSTRAINTS (MUST BE ENFORCED):
1. All text must be perfectly horizontal - ZERO rotation, ZERO tilt, ZERO angle, ZERO diagonal text
2. Use ONLY solid colors - ABSOLUTELY NO gradients, NO color transitions, NO color blends, NO gradient fills anywhere
3. All text within the same text block must be IDENTICAL size - no size variations within blocks
4. Text between different blocks can vary in size (small/medium/large)
5. Use the EXACT text provided by the user - do not translate, modify, or paraphrase
6. All text must be in the same language as provided
7. Text must be clearly readable with high contrast

Generate {$numberOfTemplates} unique, professional prompts that will create realistic, high-quality {$templateType} designs.";

            $userPrompt = "Create {$numberOfTemplates} professional {$templateType} design prompts with the following specifications:\n\n";
            
            if ($categoryName) {
                $userPrompt .= "Category: {$categoryName}\n";
            }
            if ($keywords) {
                $userPrompt .= "Keywords: {$keywords}\n";
            }
            if (!empty($textBlocks)) {
                $userPrompt .= "Text Blocks to Include:\n";
                foreach ($textBlocks as $index => $text) {
                    $size = $fontSizes[$index] ?? 'medium';
                    $userPrompt .= "  - \"{$text}\" (Size: {$size})\n";
                }
            }
            if ($fontFamily) {
                $userPrompt .= "Font Family: {$fontFamily}\n";
            }
            if ($imageStyle) {
                $userPrompt .= "Image Style: {$imageStyle}\n";
            }

            $userPrompt .= "\nFor each prompt, create a detailed, professional description that will result in a realistic, high-quality {$templateType}. Each prompt should be unique but maintain consistency with the design preferences. Return ONLY the prompts, one per line, numbered 1-{$numberOfTemplates}.";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => 'gpt-4o',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.8,
                'max_tokens' => 2000,
            ]);

            if (!$response->successful()) {
                Log::error('GPT-4 structured prompt generation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Failed to generate structured prompts: ' . $response->body());
            }

            $responseData = $response->json();
            $content = $responseData['choices'][0]['message']['content'] ?? '';

            $prompts = [];
            $lines = explode("\n", trim($content));
            
            foreach ($lines as $line) {
                $line = trim($line);
                $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);
                if (!empty($line) && strlen($line) > 20) {
                    $prompts[] = $line;
                }
            }

            $prompts = array_slice($prompts, 0, $numberOfTemplates);
            
            Log::info('GPT-4 structured prompts generated', [
                'requested' => $numberOfTemplates,
                'received' => count($prompts),
                'prompts' => $prompts,
            ]);

            return $prompts;
        } catch (\Exception $e) {
            Log::error('Structured prompt generation error: ' . $e->getMessage());
            throw $e;
        }
    }
}

