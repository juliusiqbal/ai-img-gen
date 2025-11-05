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
        int $templateCount = 4
    ): array {
        try {
            // Get image description if image provided
            $imageDescription = null;
            if ($imagePath) {
                $imageDescription = $this->analyzeImageContext($imagePath, $category->name);
            }

            // Generate prompts and images from AI
            $generatedImages = $this->openAIService->generateVariations(
                $category->name,
                $imageDescription,
                $category->details,
                $templateCount
            );

            if (empty($generatedImages)) {
                throw new \Exception('Failed to generate images from AI. Please check your API key and account balance.');
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
                    // Download the generated image
                    $downloadedPath = 'generated/' . uniqid() . '_' . time() . '_' . $index . '.png';
                    $storedPath = $this->openAIService->downloadImage(
                        $imageData['url'],
                        $downloadedPath
                    );

                    if (!$storedPath) {
                        Log::error("Failed to download image for template {$index}");
                        continue;
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
                    $template = Template::create([
                        'category_id' => $category->id,
                        'original_image_path' => $storedPath,
                        'svg_path' => $svgPath,
                        'dimensions' => $imgDimensions,
                        'printing_dimensions' => $printingDimensions ?: null,
                        'prompt_used' => $imageData['revised_prompt'] ?? null,
                    ]);

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


