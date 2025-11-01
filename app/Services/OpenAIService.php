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
     * Generate prompt from category and image context
     */
    public function generatePrompt(string $category, ?string $imageDescription = null): string
    {
        $basePrompt = "Create a professional, high-quality design template for {$category}";

        if ($imageDescription) {
            $basePrompt .= " inspired by or similar to: {$imageDescription}";
        }

        $basePrompt .= ". The design should be clean, modern, vector-style suitable for printing. Use professional colors and typography.";

        return $basePrompt;
    }

    /**
     * Generate multiple variations
     */
    public function generateVariations(string $category, ?string $imageDescription = null, int $count = 4): array
    {
        $images = [];
        $prompt = $this->generatePrompt($category, $imageDescription);

        // DALL-E 3 only supports n=1, so we need to make multiple requests
        for ($i = 0; $i < $count; $i++) {
            try {
                $variationPrompt = $prompt . " Variation " . ($i + 1);
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

