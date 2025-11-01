<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ImageProcessingService
{

    /**
     * Store uploaded image and return path
     */
    public function storeImage(UploadedFile $file, ?string $category = null): string
    {
        $filename = uniqid() . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $category ? "uploads/{$category}/{$filename}" : "uploads/{$filename}";

        $file->storeAs('public', $path);

        return $path;
    }

    /**
     * Get image dimensions
     */
    public function getImageDimensions(string $path): array
    {
        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            return ['width' => 0, 'height' => 0];
        }

        try {
            $info = getimagesize($fullPath);
            return [
                'width' => $info[0] ?? 0,
                'height' => $info[1] ?? 0,
            ];
        } catch (\Exception $e) {
            return ['width' => 0, 'height' => 0];
        }
    }

    /**
     * Validate image file
     */
    public function validateImage(UploadedFile $file): array
    {
        $errors = [];

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
        $maxSize = 10 * 1024 * 1024; // 10MB

        if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
            $errors[] = 'Invalid file type. Allowed types: JPG, PNG, WebP';
        }

        if ($file->getSize() > $maxSize) {
            $errors[] = 'File size exceeds 10MB limit';
        }

        return $errors;
    }

    /**
     * Resize image to specific dimensions using GD
     */
    public function resizeImage(string $path, int $width, int $height, string $outputPath): bool
    {
        try {
            $fullPath = storage_path('app/public/' . $path);
            $outputFullPath = storage_path('app/public/' . $outputPath);

            $info = getimagesize($fullPath);
            if (!$info) {
                return false;
            }

            $originalWidth = $info[0];
            $originalHeight = $info[1];
            $mimeType = $info['mime'];

            // Create image resource from file
            $sourceImage = match ($mimeType) {
                'image/jpeg', 'image/jpg' => imagecreatefromjpeg($fullPath),
                'image/png' => imagecreatefrompng($fullPath),
                'image/webp' => imagecreatefromwebp($fullPath),
                default => null,
            };

            if (!$sourceImage) {
                return false;
            }

            // Create new image
            $newImage = imagecreatetruecolor($width, $height);

            // Preserve transparency for PNG
            if ($mimeType === 'image/png') {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
                imagefill($newImage, 0, 0, $transparent);
            }

            // Resize
            imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $width, $height, $originalWidth, $originalHeight);

            // Save
            $dir = dirname($outputFullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $saved = match ($mimeType) {
                'image/jpeg', 'image/jpg' => imagejpeg($newImage, $outputFullPath, 90),
                'image/png' => imagepng($newImage, $outputFullPath, 9),
                'image/webp' => imagewebp($newImage, $outputFullPath, 90),
                default => false,
            };

            imagedestroy($sourceImage);
            imagedestroy($newImage);

            return $saved;
        } catch (\Exception $e) {
            Log::error('Image resize failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete image file
     */
    public function deleteImage(string $path): bool
    {
        try {
            Storage::disk('public')->delete($path);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

