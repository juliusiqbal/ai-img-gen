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
            'travel' => [
                'theme' => 'travel, vacation, tourism, leisure activities, destinations',
                'elements' => 'airplanes, travel destinations, landmarks, beaches, hotels, suitcases, maps, travel experiences, city skylines, temples, natural landscapes',
                'forbidden' => 'DO NOT include food items, restaurant imagery, or domestic/home items unrelated to travel',
                'colors' => 'vibrant travel colors, blues (sky/sea), greens (nature), warm destination colors, sunset/sunrise tones',
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
     * Refine prompt using GPT-4 for complex requests
     */
    private function refinePromptWithGPT4(string $category, ?string $categoryDetails, ?string $imageDescription = null, int $variationIndex = 0): ?string
    {
        if (empty($this->apiKey)) {
            Log::warning('OpenAI API key not configured for prompt refinement');
            return null;
        }

        try {
            $systemPrompt = "You are an expert at creating optimized prompts for DALL-E 3 image generation. Your task is to analyze user requirements and create a highly detailed, structured prompt that will generate professional, PHOTOREALISTIC advertisement templates or design templates.

CRITICAL REQUIREMENT: The output MUST be a REAL PHOTOGRAPH or PHOTOREALISTIC IMAGE. It must look like it was taken with a professional camera, not illustrated, drawn, or designed.

When creating the prompt, you must:
1. ALWAYS start with phrases like 'photorealistic photography', 'real photograph', 'professional photography', 'high-resolution photograph', or 'natural lighting photography'
2. Analyze the category and details to understand the exact requirements
3. Extract all key elements: destinations, locations, discounts, company names, text elements
4. Design an appropriate layout structure (split-screen, diagonal, overlay, etc.)
5. Specify exact text placement, sizes, and colors
6. Describe photorealistic visual elements using photography terminology (real landmarks, actual scenes, natural lighting, camera angles, depth of field)
7. Ensure all text elements are clearly visible and readable
8. Create a complete, optimized prompt ready for DALL-E 3

The output must be PHOTOREALISTIC PHOTOGRAPHY - absolutely NO vector graphics, illustrations, drawings, stylized designs, artistic styles, digital art, or graphic design. Use ONLY real photography style with:
- Actual camera photography appearance
- Real-world lighting conditions (natural sunlight, ambient lighting)
- Authentic camera angles and perspectives
- Natural depth of field and bokeh effects
- Realistic materials, textures, and surfaces
- Professional photography composition

ABSOLUTELY FORBIDDEN: Any mention of illustration, drawing, design, graphic, vector, artistic, stylized, cartoon, or abstract styles.

Return ONLY the optimized prompt text, nothing else.";

            $userPrompt = "Category: {$category}\n\n";
            $userPrompt .= "Details: {$categoryDetails}\n\n";
            
            if ($imageDescription) {
                $userPrompt .= "Reference image description: {$imageDescription}\n\n";
            }
            
            $userPrompt .= "Create an optimized DALL-E 3 prompt for generating a REAL PHOTOGRAPHIC template. ";
            $userPrompt .= "CRITICAL: The prompt MUST start with phrases emphasizing it's a real photograph (e.g., 'photorealistic photography', 'real photograph', 'professional photography'). ";
            $userPrompt .= "Include explicit layout instructions (split-screen for multiple destinations, diagonal elements, etc.), ";
            $userPrompt .= "text placement details (where each text element should appear, sizes, colors), ";
            $userPrompt .= "photorealistic photography descriptions of actual landmarks/scenes with real-world lighting and camera perspectives, ";
            $userPrompt .= "and professional advertisement layout structure using real photography composition. ";
            $userPrompt .= "Use photography terminology throughout (camera angles, depth of field, natural lighting, etc.). ";
            $userPrompt .= "The prompt must be complete, start with photorealistic photography emphasis, and be ready to use with DALL-E 3. ";
            $userPrompt .= "DO NOT mention illustration, drawing, design, or any artistic styles - ONLY real photography.";

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
                
                // Add variation-specific instructions if needed
                if ($variationIndex > 0) {
                    $variationInstructions = $this->getVariationInstructions($variationIndex);
                    if ($variationInstructions) {
                        $refinedPrompt .= " " . $variationInstructions;
                    }
                }
                
                Log::info('Prompt refined with GPT-4', [
                    'category' => $category,
                    'original_length' => strlen($categoryDetails ?? ''),
                    'refined_length' => strlen($refinedPrompt),
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
    public function generatePrompt(string $category, ?string $imageDescription = null, ?string $categoryDetails = null, int $variationIndex = 0): string
    {
        // Use GPT-4 refinement for complex requests with category details
        if (!empty($categoryDetails)) {
            $isAdvertisement = $this->isAdvertisementRequest($categoryDetails, $category);
            $isComplex = $isAdvertisement || strlen($categoryDetails) > 100 || preg_match('/\b(add|include|with|discount|percent|agency|company|name)\b/i', $categoryDetails);
            
            if ($isComplex) {
                $refinedPrompt = $this->refinePromptWithGPT4($category, $categoryDetails, $imageDescription, $variationIndex);
                if ($refinedPrompt) {
                    return $refinedPrompt;
                }
                // Fallback to basic prompt if GPT-4 refinement fails
                Log::warning('GPT-4 prompt refinement failed, using basic prompt generation');
            }
        }
        
        // Basic prompt generation (fallback or simple requests)
        $categoryTemplate = $this->getCategorySpecificPrompt($category);
        
        // Check if this is an advertisement template request
        $isAdvertisement = $this->isAdvertisementRequest($categoryDetails, $category);
        
        // Base prompt with explicit constraints - START WITH PHOTOREALISTIC REQUIREMENT
        if ($isAdvertisement) {
            $prompt = "Create a REAL PHOTOGRAPHIC advertisement template for {$category}. This must be a photorealistic photograph, not an illustration or design. ";
        } else {
            $prompt = "Create a REAL PHOTOGRAPHIC design template for {$category}. This must be a photorealistic photograph, not an illustration or design. ";
        }
        
        // Prioritize category details if provided
        if (!empty($categoryDetails)) {
            $prompt .= "Based on the following detailed requirements: {$categoryDetails}. ";
            $prompt .= "The template must incorporate ALL elements and requirements specified in the details. ";
            
            // Extract and emphasize text elements for advertisements
            if ($isAdvertisement) {
                $textElements = $this->extractTextElements($categoryDetails);
                if (!empty($textElements)) {
                    $prompt .= "IMPORTANT: Include the following text elements clearly visible in the design: " . implode(', ', $textElements) . ". ";
                    $prompt .= "Text should be readable, professional, and integrated naturally into the design. ";
                }
            }
            
            $prompt .= "The template should reflect these specific characteristics and incorporate all elements described in the details. ";
        } else {
            // Use generic category template if no details provided
            $prompt .= "MUST be relevant to {$category} and include {$categoryTemplate['theme']}. ";
            $prompt .= "Include visual elements such as: {$categoryTemplate['elements']}. ";
            $prompt .= "{$categoryTemplate['forbidden']}. ";
        }
        
        // Add image description if available
        if ($imageDescription) {
            $prompt .= "The design should be inspired by these visual elements from the reference: {$imageDescription}. ";
            $prompt .= "CRITICAL: Even if the reference image is illustrative, stylized, or designed, the output MUST be a REAL PHOTOGRAPH. ";
            $prompt .= "Convert all visual elements into photorealistic photography with actual camera-captured appearance. ";
            $prompt .= "Maintain similar color scheme and composition, but render everything as a real photograph with natural lighting, realistic shadows, authentic textures, and professional camera photography. ";
            $prompt .= "Ensure all elements are relevant to {$category} and appear as if photographed with a professional camera in real life. ";
        }
        
        // Style requirements - STRONG PHOTOREALISTIC EMPHASIS
        $prompt .= "This MUST be a REAL PHOTOGRAPH taken with a professional camera. ";
        $prompt .= "Use photorealistic photography with real-world lighting conditions, authentic camera angles, natural depth of field, and professional photography composition. ";
        $prompt .= "The image must look like a high-resolution photograph captured with a DSLR or professional camera, not created digitally. ";
        $prompt .= "ABSOLUTELY FORBIDDEN: vector graphics, illustrations, drawings, stylized designs, artistic styles, digital art, graphic design, flat design, cartoon styles, or abstract elements. ";
        $prompt .= "Use natural lighting (sunlight, ambient light), realistic shadows with proper depth, authentic textures, and materials that look photographed. ";
        $prompt .= "Every element must appear as if it was photographed in real life with natural colors, realistic materials, and authentic appearances. ";
        $prompt .= "The final output must be indistinguishable from a real professional photograph. ";
        
        // Advertisement-specific requirements
        if ($isAdvertisement) {
            $prompt .= "The advertisement should be a real photographic composition with professional, eye-catching layout and clear visual hierarchy. ";
            $prompt .= "Use actual photographed images of real destinations, real products, or real services relevant to the advertisement - all photographed, not illustrated. ";
            $prompt .= "Text overlays should be clearly visible, readable, and appear as if photographed on the actual scene (not digitally added). ";
            $prompt .= "The overall photograph should look like a professional advertisement photograph suitable for print advertising, posters, or promotional materials. ";
        }
        
        // Only add generic color template if no category details provided
        if (empty($categoryDetails)) {
            $prompt .= "Use {$categoryTemplate['colors']} in natural, realistic tones. ";
        }
        
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
     * Check if the request is for an advertisement template
     */
    private function isAdvertisementRequest(?string $categoryDetails, string $category): bool
    {
        if (empty($categoryDetails)) {
            return false;
        }
        
        $advertisementKeywords = [
            'advertisement', 'advert', 'ad', 'promo', 'promotion', 'promotional',
            'marketing', 'poster', 'banner', 'flyer', 'brochure', 'campaign'
        ];
        
        $detailsLower = strtolower($categoryDetails);
        foreach ($advertisementKeywords as $keyword) {
            if (strpos($detailsLower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Extract text elements from category details
     */
    private function extractTextElements(string $categoryDetails): array
    {
        $textElements = [];
        
        // Extract destinations/locations - pattern: "in X", "for X", "travel in X Y"
        // Handle multiple destinations like "bali singapur"
        if (preg_match_all('/\b(?:in|for|to|visit|travel|tour)\s+([a-z]+(?:\s+[a-z]+){0,3})/i', $categoryDetails, $matches)) {
            foreach ($matches[1] as $match) {
                $location = trim($match);
                // Skip common words that aren't locations
                $skipWords = ['the', 'a', 'an', 'and', 'or', 'with', 'add'];
                $words = explode(' ', strtolower($location));
                $validWords = array_filter($words, function($word) use ($skipWords) {
                    return !in_array($word, $skipWords) && strlen($word) > 2;
                });
                if (!empty($validWords)) {
                    $location = implode(' ', $validWords);
                    $textElements[] = ucwords($location);
                }
            }
        }
        
        // Extract discount percentages - pattern: "20% discount", "20% off", "20 percent", "add 20% discount"
        if (preg_match_all('/(?:add\s+)?(\d+)\s*%?\s*(?:off|discount|disc|percent)/i', $categoryDetails, $matches)) {
            foreach ($matches[1] as $match) {
                $discount = trim($match) . '%';
                $textElements[] = $discount . ' OFF';
            }
        }
        
        // Extract agency/company names - pattern: "add agency name X", "add name X"
        // Handle "akashbari tour and travels"
        if (preg_match_all('/add\s+(?:agency\s+name|company\s+name|name)\s+([a-z]+(?:\s+(?:and|tour|travels|travel|agency|company)?\s*[a-z]+){1,5})/i', $categoryDetails, $matches)) {
            foreach ($matches[1] as $match) {
                $name = trim($match);
                if (!empty($name) && strlen($name) > 3) {
                    $textElements[] = ucwords($name);
                }
            }
        }
        
        // Also extract standalone company names with common patterns
        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+(?:and|tour|travels|travel|agency|company)\s*[A-Z][a-z]+)+)/', $categoryDetails, $matches)) {
            foreach ($matches[1] as $match) {
                $name = trim($match);
                // Check if it looks like a company name (has tour, travels, agency, etc.)
                if (preg_match('/\b(tour|travels|travel|agency|company)\b/i', $name)) {
                    $textElements[] = $name;
                }
            }
        }
        
        // Extract any quoted text (likely to be important text elements)
        if (preg_match_all('/"([^"]+)"|\'([^\']+)\'/', $categoryDetails, $matches)) {
            foreach ($matches[1] as $match) {
                if (!empty($match)) {
                    $textElements[] = trim($match);
                }
            }
            foreach ($matches[2] as $match) {
                if (!empty($match)) {
                    $textElements[] = trim($match);
                }
            }
        }
        
        // Remove duplicates and empty values
        $textElements = array_filter(array_unique($textElements), function($element) {
            return !empty(trim($element));
        });
        
        return array_values($textElements);
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
    public function generateVariations(string $category, ?string $imageDescription = null, ?string $categoryDetails = null, int $count = 4): array
    {
        $images = [];

        // DALL-E 3 only supports n=1, so we need to make multiple requests
        for ($i = 0; $i < $count; $i++) {
            try {
                // Generate variation-specific prompt with category details
                $variationPrompt = $this->generatePrompt($category, $imageDescription, $categoryDetails, $i);
                
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

