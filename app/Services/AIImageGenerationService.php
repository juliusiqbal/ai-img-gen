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
        ?array $designPreferences = null
    ): array {
        try {
            $hasUploadedImages = !empty($imagePaths) || !empty($imagePath);
            $uploadedImagePaths = $hasUploadedImages ? (!empty($imagePaths) ? $imagePaths : [$imagePath]) : [];
            $imageDescription = null;

            if (!empty($uploadedImagePaths)) {
                $imageDescription = $this->analyzeMultipleImagesContext($uploadedImagePaths, $category->name);
            }

            $hasStructuredPreferences = !empty($designPreferences) && (
                !empty($designPreferences['template_type']) ||
                !empty($designPreferences['keywords'])
            );

            if ($hasStructuredPreferences) {
                Log::info('Using structured design preferences for generation', [
                    'category' => $category->name,
                    'preferences' => $designPreferences,
                ]);

                $designPreferences['category_name'] = $category->name;
                $designPreferences['number_of_templates'] = $templateCount;

                $structuredPrompts = $this->openAIService->generateStructuredPrompts($designPreferences);

                $generatedImages = [];
                foreach ($structuredPrompts as $index => $prompt) {
                    try {
                        $result = $this->openAIService->generateImage($prompt);
                        if (!empty($result)) {
                            $result[0]['generation_prompt'] = $prompt;
                            $generatedImages = array_merge($generatedImages, $result);
                        }
                        if ($index < count($structuredPrompts) - 1) {
                            sleep(1);
                        }
                    } catch (\Exception $e) {
                        Log::error("Failed to generate image with structured prompt " . ($index + 1) . ": " . $e->getMessage());
                        continue;
                    }
                }
            } else {
                Log::info('Using GPT-4 enhanced prompts with GPT Image 1', [
                    'category' => $category->name,
                    'has_images' => $hasUploadedImages,
                ]);

                $generatedImages = $this->openAIService->generateVariations(
                    $category->name,
                    $imageDescription,
                    $templateCount,
                    $uploadedImagePaths,
                    true
                );
            }

            if (empty($generatedImages)) {
                throw new \Exception('Failed to generate images. Please check your API key and account balance.');
            }

            $templates = [];
            $aspectRatio = null;

            if (!empty($printingDimensions)) {
                if (isset($printingDimensions['width']) && isset($printingDimensions['height'])) {
                    $aspectRatio = $this->dimensionCalculator->calculateAspectRatio(
                        $printingDimensions['width'],
                        $printingDimensions['height']
                    );
                }
            }

            foreach ($generatedImages as $index => $imageData) {
                try {
                    if (isset($imageData['path']) && !isset($imageData['url'])) {
                        $storedPath = $imageData['path'];
                    } else {
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

                    $imgDimensions = $this->imageService->getImageDimensions($storedPath);

                    $svgDimensions = $printingDimensions
                        ? $this->dimensionCalculator->calculateSVGViewBox($printingDimensions, $aspectRatio)
                        : ['width' => $imgDimensions['width'], 'height' => $imgDimensions['height'], 'viewBox' => "0 0 {$imgDimensions['width']} {$imgDimensions['height']}"];

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

                    $this->svgService->updateSVGDimensions(
                        storage_path('app/public/' . $svgPath),
                        $svgDimensions['width'],
                        $svgDimensions['height']
                    );

                    $this->svgService->optimizeSVG(storage_path('app/public/' . $svgPath));

                    $templateData = [
                        'category_id' => $category->id,
                        'original_image_path' => $storedPath,
                        'svg_path' => $svgPath,
                        'dimensions' => $imgDimensions,
                        'printing_dimensions' => $printingDimensions ?: null,
                        'prompt_used' => $imageData['revised_prompt'] ?? null,
                    ];

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
            $description = $this->openAIService->analyzeMultipleImagesWithVision($imagePaths, $category);

            if ($description) {
                Log::info('Multiple images analyzed successfully', [
                    'image_count' => count($imagePaths),
                    'category' => $category,
                ]);
                return $description;
            }

            Log::warning('Multiple image analysis failed, using fallback', [
                'image_count' => count($imagePaths),
                'category' => $category,
            ]);
            return "A design template incorporating multiple images with visual elements relevant to {$category}";
        } catch (\Exception $e) {
            Log::error('Error analyzing multiple images context: ' . $e->getMessage());
            return "A design template incorporating multiple images with visual elements relevant to {$category}";
        }
    }

    /**
     * Analyze image context using GPT-4 Vision API
     */
    private function analyzeImageContext(string $imagePath, string $category): ?string
    {
        try {
            $description = $this->openAIService->analyzeImageWithVision($imagePath, $category);

            if ($description) {
                Log::info('Image analyzed successfully', [
                    'image_path' => $imagePath,
                    'category' => $category,
                ]);
                return $description;
            }

            Log::warning('Image analysis failed, using fallback', [
                'image_path' => $imagePath,
                'category' => $category,
            ]);
            return "A design template with visual elements relevant to {$category}";
        } catch (\Exception $e) {
            Log::error('Error analyzing image context: ' . $e->getMessage());
            return "A design template with visual elements relevant to {$category}";
        }
    }
}
