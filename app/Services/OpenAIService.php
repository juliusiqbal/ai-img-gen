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
     * Generate image using DALL-E 3
     */
    public function generateImage(string $prompt, string $size = '1024x1024', int $n = 1): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('OpenAI API key is not configured');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->retry(2, 500)->timeout(120)->post("{$this->baseUrl}/images/generations", [
                'model' => 'dall-e-3',
                'prompt' => $prompt,
                'n' => $n,
                'size' => $size,
                'quality' => 'standard',
                'response_format' => 'url',
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
                    if (isset($item['url'])) {
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

            // Read and encode image to base64
            $imageData = file_get_contents($fullImagePath);
            $base64Image = base64_encode($imageData);
            
            // Determine MIME type
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
     * Get category-specific prompt template
     */
    private function getCategorySpecificPrompt(string $category): array
    {
        $categoryLower = strtolower(trim($category));
        
        // Category-specific templates
        $templates = [
            'restaurant' => [
                'theme' => 'culinary themes, food service, dining, hospitality',
                'elements' => 'utensils, dishes, plates, food, beverages, restaurant interiors, kitchen equipment, chef elements',
                'forbidden' => 'DO NOT include monuments, historical landmarks, travel destinations, or any non-food related imagery',
                'colors' => 'warm, appetizing colors like reds, oranges, warm whites, or professional blues and greens',
            ],
            'business' => [
                'theme' => 'professional, corporate, modern office environment',
                'elements' => 'office equipment, charts, graphs, handshakes, buildings with glass facades, technology, professional settings',
                'forbidden' => 'DO NOT include food items, restaurants, or unrelated leisure activities',
                'colors' => 'professional tones such as blues, greys, whites, with accent colors',
            ],
            'holiday' => [
                'theme' => 'travel, vacation, tourism, leisure activities',
                'elements' => 'airplanes, travel destinations, landmarks, beaches, hotels, suitcases, maps, travel experiences',
                'forbidden' => 'DO NOT include food items, restaurant imagery, or domestic/home items unrelated to travel',
                'colors' => 'vibrant travel colors, blues (sky/sea), greens (nature), warm destination colors',
            ],
            'garden' => [
                'theme' => 'gardening, plants, outdoor spaces, nature',
                'elements' => 'plants, flowers, gardening tools, pots, leaves, outdoor settings, garden layouts',
                'forbidden' => 'DO NOT include historical monuments, buildings, or travel destinations unrelated to gardening',
                'colors' => 'greens, earth tones, natural colors, vibrant flower colors',
            ],
        ];

        // Return specific template or generic fallback
        if (isset($templates[$categoryLower])) {
            return $templates[$categoryLower];
        }

        // Generic fallback
        return [
            'theme' => "professional {$category} theme",
            'elements' => "relevant visual elements for {$category}",
            'forbidden' => "DO NOT include elements unrelated to {$category}",
            'colors' => 'professional, modern color palette',
        ];
    }

    /**
     * Generate prompt from category and image context with enhanced constraints
     */
    public function generatePrompt(string $category, ?string $imageDescription = null, int $variationIndex = 0): string
    {
        $categoryTemplate = $this->getCategorySpecificPrompt($category);
        
        // Base prompt with explicit constraints
        $prompt = "Create a professional, high-quality design template for {$category}. ";
        $prompt .= "MUST be relevant to {$category} and include {$categoryTemplate['theme']}. ";
        $prompt .= "Include visual elements such as: {$categoryTemplate['elements']}. ";
        $prompt .= "{$categoryTemplate['forbidden']}. ";
        
        // Add image description if available
        if ($imageDescription) {
            $prompt .= "The design should be inspired by these visual elements from the reference: {$imageDescription}. ";
            $prompt .= "IMPORTANT: Even if the reference image is illustrative or stylized, the output MUST be photorealistic and realistic. ";
            $prompt .= "Maintain similar color scheme and composition, but render everything in photorealistic style with natural lighting, shadows, and textures. ";
            $prompt .= "Ensure all elements are relevant to {$category} and look like real photographs. ";
        }
        
        // Style requirements - PHOTOREALISTIC, not illustrative
        $prompt .= "The design must be PHOTOREALISTIC and realistic, using high-quality photography or photorealistic 3D rendering. ";
        $prompt .= "DO NOT use vector-style, flat design, illustrative, cartoon, or graphic design styles. ";
        $prompt .= "DO NOT use stylized, simplified, or abstract visual elements. ";
        $prompt .= "Use natural lighting, realistic shadows, depth, and texture. ";
        $prompt .= "Elements should look like real photographs with natural colors, realistic materials, and authentic appearances. ";
        $prompt .= "Use {$categoryTemplate['colors']} in natural, realistic tones. ";
        $prompt .= "Professional typography should be incorporated. ";
        $prompt .= "Ensure all visual elements are semantically consistent with the {$category} category - no mismatched or unrelated imagery.";
        
        // Add variation-specific instructions
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
    public function generateVariations(string $category, ?string $imageDescription = null, int $count = 4): array
    {
        $images = [];

        // DALL-E 3 only supports n=1, so we need to make multiple requests
        for ($i = 0; $i < $count; $i++) {
            try {
                // Generate variation-specific prompt
                $variationPrompt = $this->generatePrompt($category, $imageDescription, $i);
                
                $result = $this->generateImage($variationPrompt);

                if (!empty($result)) {
                    $images = array_merge($images, $result);
                }

                // Small delay to avoid rate limiting
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
}

