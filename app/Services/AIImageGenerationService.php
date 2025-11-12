<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Template;
use App\Models\GenerationJob;
use Illuminate\Support\Facades\Log;

class AIImageGenerationService
{
    public function __construct(
        private OpenAIService $openAIService,
        private SVGConversionService $svgService,
        private DimensionCalculatorService $dimensionCalculator,
        private ImageProcessingService $imageService
    ) {}

    /**
     * Generate templates for a category
     */
    public function generateTemplates(
        Category $category,
        ?string $imagePath = null,
        array $printingDimensions = [],
        int $templateCount = 1,
        array $imagePaths = [],
        bool $useDalle3 = false,
        ?array $designPreferences = null
    ): array {
        try {
            // Determine if we have uploaded images
            $hasUploadedImages = !empty($imagePaths) || !empty($imagePath);

            // Both options use DALL-E 3, but with different prompt quality
            $uploadedImagePaths = $hasUploadedImages ? (!empty($imagePaths) ? $imagePaths : [$imagePath]) : [];
            $imageDescription = null;

            // If images uploaded, analyze them for context (for both options)
            if (!empty($uploadedImagePaths)) {
                $imageDescription = $this->analyzeMultipleImagesContext($uploadedImagePaths, $category->name);
            }

            $hasStructuredPreferences = !empty($designPreferences) && (
                !empty($designPreferences['template_type']) ||
                !empty($designPreferences['keywords'])
            );

            if ($hasStructuredPreferences) {
                // Use structured prompts from GPT-4
                Log::info('Using structured design preferences for generation', [
                    'category' => $category->name,
                    'preferences' => $designPreferences,
                ]);

                // Merge category info into design preferences
                $designPreferences['category_name'] = $category->name;
                $designPreferences['category_details'] = $category->details ?? '';
                $designPreferences['number_of_templates'] = $templateCount;

                // Generate structured prompts using GPT-4
                $structuredPrompts = $this->openAIService->generateStructuredPrompts($designPreferences);

                // Generate images using the structured prompts
                $generatedImages = [];
                foreach ($structuredPrompts as $index => $prompt) {
                    try {
                        $result = $this->openAIService->generateImage($prompt);
                        if (!empty($result)) {
                            $result[0]['generation_prompt'] = $prompt; // Store the prompt
                            $generatedImages = array_merge($generatedImages, $result);
                        }
                        // Small delay to avoid rate limiting
                        if ($index < count($structuredPrompts) - 1) {
                            sleep(1);
                        }
                    } catch (\Exception $e) {
                        Log::error("Failed to generate image with structured prompt " . ($index + 1) . ": " . $e->getMessage());
                        continue;
                    }
                }
            } elseif ($useDalle3) {
                // User chose DALL-E 3 - use standard/basic prompts
                Log::info('Using DALL-E 3 with standard prompts', [
                    'category' => $category->name,
                    'has_images' => $hasUploadedImages,
                ]);

                // Generate with standard prompts (minimal GPT-4 refinement)
                $generatedImages = $this->openAIService->generateVariations(
                    $category->name,
                    $imageDescription,
                    $category->details,
                    $templateCount,
                    $uploadedImagePaths,
                    false // Use standard prompts, not enhanced GPT-4 refinement
                );
            } else {
                // User chose GPT-4 - use enhanced GPT-4 prompt refinement for professional results
                Log::info('Using GPT-4 enhanced prompts with DALL-E 3', [
                    'category' => $category->name,
                    'has_images' => $hasUploadedImages,
                ]);

                // Generate with GPT-4 enhanced prompt refinement (like ChatGPT)
                $generatedImages = $this->openAIService->generateVariations(
                    $category->name,
                    $imageDescription,
                    $category->details,
                    $templateCount,
                    $uploadedImagePaths,
                    true // Force GPT-4 enhanced prompt refinement
                );
            }

            if (empty($generatedImages)) {
                throw new \Exception('Failed to generate images. Please check your API key and account balance.');
            }

            $templates = [];
            $aspectRatio = null;

            // Calculate dimensions if provided
            if (!empty($printingDimensions)) {
                if (isset($printingDimensions['width']) && isset($printingDimensions['height'])) {
                    $aspectRatio = $this->dimensionCalculator->calculateAspectRatio(
                        $printingDimensions['width'],
                        $printingDimensions['height']
                    );
                }
            }

            // Process each generated image
            foreach ($generatedImages as $index => $imageData) {
                try {
                    // Handle composite templates (already saved) vs DALL-E generated images (need download)
                    if (isset($imageData['path']) && !isset($imageData['url'])) {
                        // Composite template - already saved, use the path
                        $storedPath = $imageData['path'];
                    } else {
                        // DALL-E generated image - download it
                        $downloadedPath = 'generated/' . uniqid() . '_' . time() . '_' . $index . '.png';
                        $storedPath = $this->openAIService->downloadImage(
                            $imageData['url'],
                            $downloadedPath
                        );

                        if (!$storedPath) {
                            Log::error("Failed to download image for template {$index}");
                            continue;
                        }
                    }

                    // Get image dimensions
                    $imgDimensions = $this->imageService->getImageDimensions($storedPath);

                    // Calculate SVG dimensions
                    $svgDimensions = $printingDimensions
                        ? $this->dimensionCalculator->calculateSVGViewBox($printingDimensions, $aspectRatio)
                        : ['width' => $imgDimensions['width'], 'height' => $imgDimensions['height'], 'viewBox' => "0 0 {$imgDimensions['width']} {$imgDimensions['height']}"];

                    // Convert to SVG
                    $svgPath = 'svgs/' . uniqid() . '_' . time() . '_' . $index . '.svg';
                    $converted = $this->svgService->convertToSVGWithPotrace(
                        $storedPath,
                        $svgPath,
                        [
                            'width' => $svgDimensions['width'],
                            'height' => $svgDimensions['height'],
                        ]
                    );

                    if (!$converted) {
                        Log::error("Failed to convert image to SVG for template {$index}");
                        continue;
                    }

                    // Update SVG dimensions
                    $this->svgService->updateSVGDimensions(
                        storage_path('app/public/' . $svgPath),
                        $svgDimensions['width'],
                        $svgDimensions['height']
                    );

                    // Optimize SVG
                    $this->svgService->optimizeSVG(storage_path('app/public/' . $svgPath));

                    // Create template record
                    $templateData = [
                        'category_id' => $category->id,
                        'original_image_path' => $storedPath,
                        'svg_path' => $svgPath,
                        'dimensions' => $imgDimensions,
                        'printing_dimensions' => $printingDimensions ?: null,
                        'prompt_used' => $imageData['revised_prompt'] ?? null,
                    ];

                    // Add structured design preferences if provided
                    if (!empty($designPreferences)) {
                        $templateData['project_name'] = $designPreferences['project_name'] ?? null;
                        $templateData['generation_prompt'] = $imageData['generation_prompt'] ?? $imageData['revised_prompt'] ?? null;
                        $templateData['design_preferences'] = $designPreferences;
                    }

                    $template = Template::create($templateData);

                    $templates[] = $template;
                } catch (\Exception $e) {
                    Log::error("Error processing template {$index}: " . $e->getMessage());
                    continue;
                }
            }

            return $templates;
        } catch (\Exception $e) {
            Log::error('Template generation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Analyze multiple images context using GPT-4 Vision API
     */
    private function analyzeMultipleImagesContext(array $imagePaths, string $category): ?string
    {
        try {
            // Use GPT-4 Vision API to analyze multiple uploaded images
            $description = $this->openAIService->analyzeMultipleImagesWithVision($imagePaths, $category);

            if ($description) {
                Log::info('Multiple images analyzed successfully', [
                    'image_count' => count($imagePaths),
                    'category' => $category,
                ]);
                return $description;
            }

            // Fallback if vision API fails
            Log::warning('Multiple image analysis failed, using fallback', [
                'image_count' => count($imagePaths),
                'category' => $category,
            ]);
            return "A design template incorporating multiple images with visual elements relevant to {$category}";
        } catch (\Exception $e) {
            Log::error('Error analyzing multiple images context: ' . $e->getMessage());
            // Return a fallback description
            return "A design template incorporating multiple images with visual elements relevant to {$category}";
        }
    }

    /**
     * Analyze image context using GPT-4 Vision API
     */
    private function analyzeImageContext(string $imagePath, string $category): ?string
    {
        try {
            // Use GPT-4 Vision API to analyze the uploaded image
            $description = $this->openAIService->analyzeImageWithVision($imagePath, $category);

            if ($description) {
                Log::info('Image analyzed successfully', [
                    'image_path' => $imagePath,
                    'category' => $category,
                ]);
                return $description;
            }

            // Fallback if vision API fails
            Log::warning('Image analysis failed, using fallback', [
                'image_path' => $imagePath,
                'category' => $category,
            ]);
            return "A design template with visual elements relevant to {$category}";
        } catch (\Exception $e) {
            Log::error('Error analyzing image context: ' . $e->getMessage());
            // Return a fallback description
            return "A design template with visual elements relevant to {$category}";
        }
    }
}


