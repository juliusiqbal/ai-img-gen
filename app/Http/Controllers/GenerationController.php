<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\GenerationJob;
use App\Services\AIImageGenerationService;
use App\Services\DimensionCalculatorService;
use App\Services\ImageProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerationController extends Controller
{
    public function __construct(
        private AIImageGenerationService $aiService,
        private DimensionCalculatorService $dimensionCalculator,
        private ImageProcessingService $imageService
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
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:10240',
            'width' => 'nullable|numeric|min:1',
            'height' => 'nullable|numeric|min:1',
            'unit' => 'nullable|in:mm,cm,inches,in,pixels,px',
            'standard_size' => 'nullable|string',
            'template_count' => 'nullable|integer|min:1|max:10',
        ]);

        try {
            DB::beginTransaction();

            // Get or create category
            if ($request->category_id) {
                $category = Category::findOrFail($request->category_id);
            } elseif ($request->category_name) {
                $category = Category::firstOrCreate(['name' => $request->category_name]);
            } else {
                return response()->json(['error' => 'Category ID or name is required'], 400);
            }

            // Handle image upload
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $this->imageService->storeImage($request->file('image'), $category->name);
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
            $templateCount = $request->template_count ?? 4;
            $templates = $this->aiService->generateTemplates(
                $category,
                $imagePath,
                $printingDimensions,
                $templateCount
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
     * Get job status
     */
    public function jobStatus(string $id): JsonResponse
    {
        $job = GenerationJob::with('category')->findOrFail($id);
        return response()->json($job);
    }
}
