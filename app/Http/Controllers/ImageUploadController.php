<?php

namespace App\Http\Controllers;

use App\Services\ImageProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImageUploadController extends Controller
{
    public function __construct(
        private ImageProcessingService $imageService
    ) {}

    /**
     * Upload and validate image
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,webp|max:10240',
        ]);

        $errors = $this->imageService->validateImage($request->file('image'));

        if (!empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        $path = $this->imageService->storeImage($request->file('image'));
        $dimensions = $this->imageService->getImageDimensions($path);

        return response()->json([
            'message' => 'Image uploaded successfully',
            'path' => $path,
            'url' => asset('storage/' . $path),
            'dimensions' => $dimensions,
        ], 201);
    }
}
