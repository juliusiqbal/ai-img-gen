<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\GenerationJob;
use App\Models\Template;
use App\Services\AIImageGenerationService;
use App\Services\DimensionCalculatorService;
use App\Services\ImageProcessingService;
use App\Services\OpenAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerationController extends Controller
{
    public function __construct(
        private AIImageGenerationService $aiService,
        private DimensionCalculatorService $dimensionCalculator,
        private ImageProcessingService $imageService,
        private OpenAIService $openAIService
    ) {}

    /**
     * Generate templates
     */
    public function generate(Request $request): JsonResponse
    {
        // Allow longer-running AI generation without hitting PHP's 60s limit in dev
        \set_time_limit(300);

        $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'category_name' => 'nullable|string|max:255',
            'category_details' => 'nullable|string|max:2000',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:10240',
            'images' => 'nullable|array',
            'images.*' => 'nullable|image|mimes:jpeg,png,webp|max:10240',
            'width' => 'nullable|numeric|min:1',
            'height' => 'nullable|numeric|min:1',
            'unit' => 'nullable|in:mm,cm,inches,in,pixels,px',
            'standard_size' => 'nullable|string',
            'template_count' => 'nullable|integer|min:1|max:10',
            'use_dalle3' => 'nullable|boolean',
            // Structured design preferences
            'project_name' => 'nullable|string|max:255',
            'template_type' => 'nullable|in:poster,banner,brochure,postcard,flyer,social',
            'keywords' => 'nullable|string|max:500',
            'font_family' => 'nullable|in:arial,helvetica,serif,sans-serif,times,courier',
            'font_sizes' => 'nullable|array',
            'color_theme' => 'nullable|string|max:50',
            'background_color' => 'nullable|string|max:50',
            'image_style' => 'nullable|string|max:50',
        ]);

        try {
            DB::beginTransaction();

            // Get or create category
            if ($request->category_id) {
                $category = Category::findOrFail($request->category_id);
                // Update category details if provided
                if ($request->has('category_details') && $request->category_details) {
                    $category->update(['details' => $request->category_details]);
                }
            } elseif ($request->category_name) {
                $category = Category::firstOrCreate(
                    ['name' => $request->category_name],
                    ['details' => $request->category_details ?? null]
                );
                // If category already exists and details are provided, update details
                if (!$category->wasRecentlyCreated && $request->has('category_details') && $request->category_details) {
                    $category->update(['details' => $request->category_details]);
                }
            } else {
                return response()->json(['error' => 'Category ID or name is required'], 400);
            }

            // Handle image upload(s) - support both single and multiple images
            $imagePath = null;
            $imagePaths = [];

            if ($request->hasFile('images') && is_array($request->file('images'))) {
                // Multiple images provided
                foreach ($request->file('images') as $file) {
                    if ($file && $file->isValid()) {
                        $storedPath = $this->imageService->storeImage($file, $category->name);
                        if ($storedPath) {
                            $imagePaths[] = $storedPath;
                        }
                    }
                }
            } elseif ($request->hasFile('image')) {
                // Single image provided (backward compatibility)
                $imagePath = $this->imageService->storeImage($request->file('image'), $category->name);
                if ($imagePath) {
                    $imagePaths = [$imagePath];
                }
            }

            // Calculate printing dimensions
            $printingDimensions = [];
            if ($request->standard_size) {
                $standardSize = $this->dimensionCalculator->getStandardSize($request->standard_size);
                if ($standardSize) {
                    $printingDimensions = array_merge($standardSize, ['unit' => 'mm']);
                }
            } elseif ($request->width && $request->height) {
                $printingDimensions = [
                    'width' => (float) $request->width,
                    'height' => (float) $request->height,
                    'unit' => $request->unit ?? 'mm',
                ];
            }

            // Create generation job
            $job = GenerationJob::create([
                'category_id' => $category->id,
                'status' => 'processing',
                'request_data' => $request->all(),
            ]);

            // Generate templates
            $templateCount = $request->template_count ?? 1;
            $useDalle3 = filter_var($request->use_dalle3 ?? false, FILTER_VALIDATE_BOOLEAN);

            $designPreferences = null;
            if ($request->has('template_type') || $request->has('keywords')) {
                $fontSizes = [];
                if ($request->has('font_sizes')) {
                    $fontSizesInput = $request->input('font_sizes', []);
                    if (is_array($fontSizesInput)) {
                        $fontSizes = array_values($fontSizesInput);
                    }
                }

                $designPreferences = [
                    'template_type' => $request->template_type,
                    'keywords' => $request->keywords,
                    'font_family' => $request->font_family,
                    'font_sizes' => $fontSizes,
                    'color_theme' => $request->color_theme,
                    'background_color' => $request->background_color,
                    'image_style' => $request->image_style,
                    'project_name' => $request->project_name,
                ];
            }

            $templates = $this->aiService->generateTemplates(
                $category,
                $imagePath,
                $printingDimensions,
                $templateCount,
                $imagePaths,
                $useDalle3,
                $designPreferences
            );

            $job->update([
                'status' => 'completed',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Templates generated successfully',
                'category' => $category,
                'templates' => $templates,
                'job_id' => $job->id,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            if (isset($job)) {
                $job->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            // Parse OpenAI error messages for user-friendly responses
            $errorMessage = $e->getMessage();
            $statusCode = 500;

            if (str_contains($errorMessage, 'Billing hard limit has been reached') ||
                str_contains($errorMessage, 'billing_hard_limit_reached')) {
                $errorMessage = 'OpenAI API billing limit reached. Please add credits to your OpenAI account or check your billing settings.';
                $statusCode = 402; // Payment Required
            } elseif (str_contains($errorMessage, 'Invalid API key') ||
                      str_contains($errorMessage, 'authentication')) {
                $errorMessage = 'Invalid OpenAI API key. Please check your API key in the .env file.';
                $statusCode = 401;
            } elseif (str_contains($errorMessage, 'rate limit') ||
                      str_contains($errorMessage, 'rate_limit_exceeded')) {
                $errorMessage = 'API rate limit exceeded. Please try again in a few moments.';
                $statusCode = 429;
            } elseif (str_contains($errorMessage, 'insufficient quota')) {
                $errorMessage = 'Insufficient API quota. Please check your OpenAI account balance.';
                $statusCode = 402;
            }

            Log::error('Template generation error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Generation failed',
                'message' => $errorMessage,
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], $statusCode);
        }
    }

    /**
     * Preview GPT-4 generated prompts without generating images
     */
    public function previewPrompts(Request $request): JsonResponse
    {
        $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'category_name' => 'nullable|string|max:255',
            'category_details' => 'nullable|string|max:2000',
            'template_type' => 'nullable|in:poster,banner,brochure,postcard,flyer,social',
            'keywords' => 'nullable|string|max:500',
            'font_family' => 'nullable|in:arial,helvetica,serif,sans-serif,times,courier',
            'font_sizes' => 'nullable|array',
            'color_theme' => 'nullable|string|max:50',
            'background_color' => 'nullable|string|max:50',
            'image_style' => 'nullable|string|max:50',
            'number_of_templates' => 'nullable|integer|min:1|max:10',
        ]);

        try {
            // Get category info
            $categoryName = '';
            $categoryDetails = '';

            if ($request->category_id) {
                $category = Category::findOrFail($request->category_id);
                $categoryName = $category->name;
                $categoryDetails = $category->details ?? '';
            } elseif ($request->category_name) {
                $categoryName = $request->category_name;
                $categoryDetails = $request->category_details ?? '';
            }

            $designPreferences = [
                'template_type' => $request->template_type ?? 'poster',
                'keywords' => $request->keywords ?? '',
                'font_family' => $request->font_family ?? '',
                'font_sizes' => $request->font_sizes ?? [],
                'color_theme' => $request->color_theme ?? '',
                'background_color' => $request->background_color ?? '',
                'image_style' => $request->image_style ?? 'realistic',
                'number_of_templates' => $request->number_of_templates ?? 1,
                'category_name' => $categoryName,
                'category_details' => $categoryDetails,
            ];

            $prompts = $this->openAIService->generateStructuredPrompts($designPreferences);

            return response()->json([
                'prompts' => $prompts,
                'count' => count($prompts),
            ]);
        } catch (\Exception $e) {
            Log::error('Preview prompts error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to preview prompts',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Regenerate a specific template
     */
    public function regenerateTemplate(Request $request, int $id): JsonResponse
    {
        \set_time_limit(300);

        $request->validate([
            'use_dalle3' => 'nullable|boolean',
            // Allow updating design preferences
            'template_type' => 'nullable|in:poster,banner,brochure,postcard,flyer,social',
            'keywords' => 'nullable|string|max:500',
            // text_blocks removed - now auto-generated by GPT-4o based on category and keywords
            'font_family' => 'nullable|in:arial,helvetica,serif,sans-serif,times,courier',
            'color_theme' => 'nullable|string|max:50',
            'background_color' => 'nullable|string|max:50',
            'image_style' => 'nullable|string|max:50',
        ]);

        try {
            DB::beginTransaction();

            $template = Template::with('category')->findOrFail($id);
            $category = $template->category;

            // Get original design preferences or use new ones
            $designPreferences = $template->design_preferences ?? [];

            if ($request->has('template_type')) {
                $designPreferences['template_type'] = $request->template_type;
            }
            if ($request->has('keywords')) {
                $designPreferences['keywords'] = $request->keywords;
            }
            if ($request->has('font_family')) {
                $designPreferences['font_family'] = $request->font_family;
            }
            if ($request->has('color_theme')) {
                $designPreferences['color_theme'] = $request->color_theme;
            }
            if ($request->has('background_color')) {
                $designPreferences['background_color'] = $request->background_color;
            }
            if ($request->has('image_style')) {
                $designPreferences['image_style'] = $request->image_style;
            }

            if (empty($designPreferences['category_name'])) {
                $designPreferences['category_name'] = $category->name;
            }
            if (empty($designPreferences['category_details'])) {
                $designPreferences['category_details'] = $category->details ?? '';
            }

            $useDalle3 = filter_var($request->use_dalle3 ?? true, FILTER_VALIDATE_BOOLEAN);

            $templates = $this->aiService->generateTemplates(
                $category,
                null,
                $template->printing_dimensions ?? [],
                1,
                [],
                $useDalle3,
                !empty($designPreferences) ? $designPreferences : null
            );

            if (empty($templates)) {
                throw new \Exception('Failed to regenerate template');
            }

            $newTemplate = $templates[0];

            DB::commit();

            return response()->json([
                'message' => 'Template regenerated successfully',
                'template' => $newTemplate,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Template regeneration error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Regeneration failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get job status
     */
    public function jobStatus(string $id): JsonResponse
    {
        $job = GenerationJob::with('category')->findOrFail($id);
        return response()->json($job);
    }
}
