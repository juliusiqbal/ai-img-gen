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

        $fullPath = storage_path('app/public/' . $path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            $created = @mkdir($dir, 0755, true);
            if (!$created && !is_dir($dir)) {
                Log::error("Failed to create directory", [
                    'dir' => $dir,
                    'path' => $path,
                ]);
                throw new \Exception("Failed to create upload directory: {$dir}");
            }
        }

        try {
            $stored = Storage::disk('public')->putFileAs(
                dirname($path),
                $file,
                basename($path)
            );

            if (!$stored) {
                Log::error("Storage::putFileAs returned false", [
                    'path' => $path,
                    'category' => $category,
                ]);
                throw new \Exception("Failed to store uploaded image: {$path}");
            }

            $exists = Storage::disk('public')->exists($path);
            $fullStoragePath = storage_path('app/public/' . $path);
            $fileExists = file_exists($fullStoragePath);

            if (!$exists || !$fileExists) {
                Log::error("File not found after storage", [
                    'path' => $path,
                    'stored' => $stored,
                    'storage_exists' => $exists,
                    'file_exists' => $fileExists,
                    'full_path' => $fullStoragePath,
                ]);
                throw new \Exception("Failed to store uploaded image: {$path}");
            }

            Log::info("Image stored successfully", [
                'path' => $path,
                'full_path' => $fullStoragePath,
                'size' => filesize($fullStoragePath),
            ]);

            return $path;
        } catch (\Exception $e) {
            Log::error("Exception during image storage", [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
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

            $sourceImage = match ($mimeType) {
                'image/jpeg', 'image/jpg' => imagecreatefromjpeg($fullPath),
                'image/png' => imagecreatefrompng($fullPath),
                'image/webp' => imagecreatefromwebp($fullPath),
                default => null,
            };

            if (!$sourceImage) {
                return false;
            }

            $newImage = imagecreatetruecolor($width, $height);

            if ($mimeType === 'image/png') {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
                imagefill($newImage, 0, 0, $transparent);
            }

            imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $width, $height, $originalWidth, $originalHeight);

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

    /**
     * Create professional template from GPT-4 guidance when no images are uploaded
     */
    public function createProfessionalTemplateFromGPT4(
        array $dimensions = [],
        int $templateCount = 1
    ): array {
        $templates = [];
        $canvasWidth = $dimensions['width'] ?? 1024;
        $canvasHeight = $dimensions['height'] ?? 1024;

        for ($i = 0; $i < $templateCount; $i++) {
            try {
                $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);

                $this->createAttractiveBackground($canvas, $canvasWidth, $canvasHeight, $i);

                $this->addDecorativeElements($canvas, $canvasWidth, $canvasHeight, $i);

                $outputPath = 'generated/gpt4_template_' . uniqid() . '_' . time() . '_' . $i . '.png';
                $outputFullPath = storage_path('app/public/' . $outputPath);

                $dir = dirname($outputFullPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                imagepng($canvas, $outputFullPath, 9);
                imagedestroy($canvas);

                $templates[] = [
                    'url' => null,
                    'path' => $outputPath,
                    'revised_prompt' => 'GPT-4 guided professional template',
                ];
            } catch (\Exception $e) {
                Log::error("Failed to create GPT-4 professional template {$i}: " . $e->getMessage());
                continue;
            }
        }

        return $templates;
    }

    /**
     * Create composite template from uploaded images with text overlays
     */
    public function createCompositeTemplate(
        array $imagePaths,
        array $dimensions = [],
        int $templateCount = 1
    ): array {
        $templates = [];
        $canvasWidth = $dimensions['width'] ?? 1024;
        $canvasHeight = $dimensions['height'] ?? 1024;

        for ($i = 0; $i < $templateCount; $i++) {
            try {
                $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);

                $this->createAttractiveBackground($canvas, $canvasWidth, $canvasHeight, $i);

                $this->arrangeImagesOnCanvas($canvas, $imagePaths, $canvasWidth, $canvasHeight, $i);

                $outputPath = 'generated/composite_' . uniqid() . '_' . time() . '_' . $i . '.png';
                $outputFullPath = storage_path('app/public/' . $outputPath);

                $dir = dirname($outputFullPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                imagepng($canvas, $outputFullPath, 9);
                imagedestroy($canvas);

                $templates[] = [
                    'url' => null, // Not from URL
                    'path' => $outputPath,
                    'revised_prompt' => 'Composite template with uploaded images',
                ];
            } catch (\Exception $e) {
                Log::error("Failed to create composite template {$i}: " . $e->getMessage());
                continue;
            }
        }

        return $templates;
    }

    /**
     * Create attractive background for template
     */
    private function createAttractiveBackground($canvas, int $canvasWidth, int $canvasHeight, int $variationIndex): void
    {
        $bgStyles = [
            0 => ['r' => 255, 'g' => 255, 'b' => 255], // White
            1 => ['r' => 248, 'g' => 248, 'b' => 252], // Light gray-blue
            2 => ['r' => 252, 'g' => 252, 'b' => 255], // Very light blue
            3 => ['r' => 255, 'g' => 252, 'b' => 248], // Very light warm
        ];

        $style = $bgStyles[$variationIndex % count($bgStyles)];
        $bgColor = imagecolorallocate($canvas, $style['r'], $style['g'], $style['b']);
        imagefill($canvas, 0, 0, $bgColor);

        $this->addDecorativeElements($canvas, $canvasWidth, $canvasHeight, $variationIndex);
    }

    /**
     * Add decorative elements for professional look
     */
    private function addDecorativeElements($canvas, int $canvasWidth, int $canvasHeight, int $variationIndex): void
    {
        $decorColor = imagecolorallocate($canvas, 230, 230, 235);

        imageline($canvas, 0, 2, $canvasWidth, 2, $decorColor);
        imageline($canvas, 0, $canvasHeight - 3, $canvasWidth, $canvasHeight - 3, $decorColor);

        if ($variationIndex % 2 === 0) {
            $accentColor = imagecolorallocate($canvas, 240, 240, 245);
            imagefilledrectangle($canvas, 0, 0, 50, 3, $accentColor);
            imagefilledrectangle($canvas, $canvasWidth - 50, $canvasHeight - 3, $canvasWidth, $canvasHeight, $accentColor);
        }
    }

    /**
     * Arrange images on canvas with attractive layouts
     */
    private function arrangeImagesOnCanvas($canvas, array $imagePaths, int $canvasWidth, int $canvasHeight, int $variationIndex = 0): void
    {
        $imageCount = count($imagePaths);
        if ($imageCount === 0) {
            Log::warning('No image paths provided for canvas arrangement');
            return;
        }

        $images = [];
        foreach ($imagePaths as $path) {
            $fullPath = storage_path('app/public/' . $path);

            if (!file_exists($fullPath)) {
                Log::error("Image file not found: {$fullPath} (path: {$path})");
                continue;
            }

            $info = @getimagesize($fullPath);
            if (!$info) {
                Log::error("Failed to get image size: {$fullPath}");
                continue;
            }

            $mimeType = $info['mime'];
            $img = match ($mimeType) {
                'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($fullPath),
                'image/png' => @imagecreatefrompng($fullPath),
                'image/webp' => @imagecreatefromwebp($fullPath),
                default => null,
            };

            if ($img === false || $img === null) {
                Log::error("Failed to create image resource from: {$fullPath} (mime: {$mimeType})");
                continue;
            }

            $images[] = [
                'resource' => $img,
                'width' => $info[0],
                'height' => $info[1],
            ];
        }

        if (empty($images)) {
            Log::error("No valid images loaded from paths: " . implode(', ', $imagePaths));
            return;
        }

        Log::info("Successfully loaded " . count($images) . " images for canvas arrangement");

        $loadedImageCount = count($images);

        $imagePositions = [];

        if ($loadedImageCount === 1) {
            $imagePositions = $this->calculateSingleImageLayout($images[0], $canvasWidth, $canvasHeight, $variationIndex);
        } elseif ($loadedImageCount === 2) {
            $imagePositions = $this->calculateTwoImagesLayout($images, $canvasWidth, $canvasHeight, $variationIndex);
        } else {
            $imagePositions = $this->calculateMultipleImagesLayout($images, $canvasWidth, $canvasHeight, $variationIndex);
        }

        foreach ($imagePositions as $pos) {
            $this->addImageShadow($canvas, $pos['x'], $pos['y'], $pos['width'], $pos['height']);
        }

        if ($loadedImageCount === 1) {
            $this->drawSingleImage($canvas, $imagePositions[0]);
        } elseif ($loadedImageCount === 2) {
            $this->drawTwoImages($canvas, $imagePositions);
        } else {
            $this->drawMultipleImages($canvas, $imagePositions);
        }

        foreach ($imagePositions as $pos) {
            $this->addImageFrame($canvas, $pos['x'], $pos['y'], $pos['width'], $pos['height']);
        }

        foreach ($images as $img) {
            imagedestroy($img['resource']);
        }
    }

    /**
     * Add drop shadow to image
     */
    private function addImageShadow($canvas, int $x, int $y, int $width, int $height): void
    {
        $shadowOffset = 8;
        $shadowBlur = 12;

        for ($i = 0; $i < $shadowBlur; $i++) {
            $alpha = (int)(70 - ($i * 5));
            if ($alpha < 0) $alpha = 0;
            $shadowColor = imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
            $offset = $shadowOffset - ($i * 0.6);
            imagefilledrectangle(
                $canvas,
                $x + $offset,
                $y + $offset,
                $x + $width + $offset,
                $y + $height + $offset,
                $shadowColor
            );
        }
    }

    /**
     * Add professional frame/border to image
     */
    private function addImageFrame($canvas, int $x, int $y, int $width, int $height): void
    {
        $borderWidth = 3;
        $borderColor = imagecolorallocate($canvas, 240, 240, 240);
        $innerBorderColor = imagecolorallocate($canvas, 255, 255, 255);

        imagerectangle($canvas, $x - $borderWidth, $y - $borderWidth, $x + $width + $borderWidth, $y + $height + $borderWidth, $borderColor);
        imagerectangle($canvas, $x - 1, $y - 1, $x + $width + 1, $y + $height + 1, $innerBorderColor);
    }

    /**
     * Calculate single image layout positions
     */
    private function calculateSingleImageLayout(array $img, int $canvasWidth, int $canvasHeight, int $variationIndex): array
    {
        $layouts = [
            0 => 'full_bleed',      // Full width, top
            1 => 'centered',        // Centered with padding
            2 => 'offset_left',     // Left aligned, offset
            3 => 'offset_right',    // Right aligned, offset
        ];

        $layout = $layouts[$variationIndex % count($layouts)];

        switch ($layout) {
            case 'full_bleed':
                $targetWidth = $canvasWidth;
                $targetHeight = (int)($canvasHeight * 0.7); // 70% of height
                $targetHeight = (int)($img['height'] * $targetWidth / $img['width']);
                if ($targetHeight > (int)($canvasHeight * 0.7)) {
                    $targetHeight = (int)($canvasHeight * 0.7);
                    $targetWidth = (int)($img['width'] * $targetHeight / $img['height']);
                }
                $x = 0;
                $y = 0;
                break;

            case 'centered':
                $padding = 40;
                $targetWidth = $canvasWidth - ($padding * 2);
                $targetHeight = (int)($img['height'] * $targetWidth / $img['width']);
                if ($targetHeight > $canvasHeight - ($padding * 2)) {
                    $targetHeight = $canvasHeight - ($padding * 2);
                    $targetWidth = (int)($img['width'] * $targetHeight / $img['height']);
                }
                $x = (int)(($canvasWidth - $targetWidth) / 2);
                $y = (int)(($canvasHeight - $targetHeight) / 2);
                break;

            case 'offset_left':
                $offsetX = 30;
                $offsetY = 50;
                $targetWidth = (int)($canvasWidth * 0.65);
                $targetHeight = (int)($img['height'] * $targetWidth / $img['width']);
                if ($targetHeight > $canvasHeight - ($offsetY * 2)) {
                    $targetHeight = $canvasHeight - ($offsetY * 2);
                    $targetWidth = (int)($img['width'] * $targetHeight / $img['height']);
                }
                $x = $offsetX;
                $y = $offsetY;
                break;

            case 'offset_right':
                $offsetX = 30;
                $offsetY = 50;
                $targetWidth = (int)($canvasWidth * 0.65);
                $targetHeight = (int)($img['height'] * $targetWidth / $img['width']);
                if ($targetHeight > $canvasHeight - ($offsetY * 2)) {
                    $targetHeight = $canvasHeight - ($offsetY * 2);
                    $targetWidth = (int)($img['width'] * $targetHeight / $img['height']);
                }
                $x = $canvasWidth - $targetWidth - $offsetX;
                $y = $offsetY;
                break;
        }

        // Return position for frame/shadow (with padding for frame)
        $framePadding = 3;
        return [['x' => $x, 'y' => $y, 'width' => $targetWidth, 'height' => $targetHeight, 'img' => $img]];
    }

    /**
     * Draw single image on canvas
     */
    private function drawSingleImage($canvas, array $position): void
    {
        if (!isset($position['img'])) {
            return;
        }
        $img = $position['img'];
        $framePadding = 3;
        $imageX = $position['x'] + $framePadding;
        $imageY = $position['y'] + $framePadding;
        $imageWidth = $position['width'] - ($framePadding * 2);
        $imageHeight = $position['height'] - ($framePadding * 2);

        imagecopyresampled($canvas, $img['resource'], $imageX, $imageY, 0, 0, $imageWidth, $imageHeight, $img['width'], $img['height']);
    }

    /**
     * Calculate two images layout positions
     */
    private function calculateTwoImagesLayout(array $images, int $canvasWidth, int $canvasHeight, int $variationIndex): array
    {
        $layouts = [
            0 => 'l_shape',            // L-shape layout (top-left and bottom-left)
            1 => 'split_vertical',    // Left/Right split
            2 => 'overlapping',        // Overlapping with offset
            3 => 'diagonal',          // Diagonal arrangement
        ];

        $layout = $layouts[$variationIndex % count($layouts)];
        $img1 = $images[0];
        $img2 = $images[1];

        switch ($layout) {
            case 'l_shape':
                $targetWidth1 = (int)($canvasWidth * 0.5);
                $targetHeight1 = (int)($img1['height'] * $targetWidth1 / $img1['width']);
                if ($targetHeight1 > (int)($canvasHeight * 0.55)) {
                    $targetHeight1 = (int)($canvasHeight * 0.55);
                    $targetWidth1 = (int)($img1['width'] * $targetHeight1 / $img1['height']);
                }
                $x1 = 30;
                $y1 = 30;

                $targetWidth2 = (int)($canvasWidth * 0.48);
                $targetHeight2 = (int)($img2['height'] * $targetWidth2 / $img2['width']);
                if ($targetHeight2 > (int)($canvasHeight * 0.45)) {
                    $targetHeight2 = (int)($canvasHeight * 0.45);
                    $targetWidth2 = (int)($img2['width'] * $targetHeight2 / $img2['height']);
                }
                $x2 = 30;
                $y2 = $y1 + $targetHeight1 + 20;

                return [
                    ['x' => $x1, 'y' => $y1, 'width' => $targetWidth1, 'height' => $targetHeight1, 'img' => $img1],
                    ['x' => $x2, 'y' => $y2, 'width' => $targetWidth2, 'height' => $targetHeight2, 'img' => $img2],
                ];

            case 'split_vertical':
                $gap = 20;
                $halfWidth = (int)(($canvasWidth - $gap) / 2);

                $targetWidth1 = $halfWidth;
                $targetHeight1 = (int)($img1['height'] * $targetWidth1 / $img1['width']);
                if ($targetHeight1 > $canvasHeight) {
                    $targetHeight1 = $canvasHeight;
                    $targetWidth1 = (int)($img1['width'] * $targetHeight1 / $img1['height']);
                }
                $x1 = 0;
                $y1 = (int)(($canvasHeight - $targetHeight1) / 2);

                $targetWidth2 = $halfWidth;
                $targetHeight2 = (int)($img2['height'] * $targetWidth2 / $img2['width']);
                if ($targetHeight2 > $canvasHeight) {
                    $targetHeight2 = $canvasHeight;
                    $targetWidth2 = (int)($img2['width'] * $targetHeight2 / $img2['height']);
                }
                $x2 = $halfWidth + $gap;
                $y2 = (int)(($canvasHeight - $targetHeight2) / 2);

                return [
                    ['x' => $x1, 'y' => $y1, 'width' => $targetWidth1, 'height' => $targetHeight1, 'img' => $img1],
                    ['x' => $x2, 'y' => $y2, 'width' => $targetWidth2, 'height' => $targetHeight2, 'img' => $img2],
                ];

            case 'split_horizontal':
                $gap = 20;
                $halfHeight = (int)(($canvasHeight - $gap) / 2);

                $targetWidth1 = $canvasWidth;
                $targetHeight1 = (int)($img1['height'] * $targetWidth1 / $img1['width']);
                if ($targetHeight1 > $halfHeight) {
                    $targetHeight1 = $halfHeight;
                    $targetWidth1 = (int)($img1['width'] * $targetHeight1 / $img1['height']);
                }
                $x1 = (int)(($canvasWidth - $targetWidth1) / 2);
                $y1 = 0;

                $targetWidth2 = $canvasWidth;
                $targetHeight2 = (int)($img2['height'] * $targetWidth2 / $img2['width']);
                if ($targetHeight2 > $halfHeight) {
                    $targetHeight2 = $halfHeight;
                    $targetWidth2 = (int)($img2['width'] * $targetHeight2 / $img2['height']);
                }
                $x2 = (int)(($canvasWidth - $targetWidth2) / 2);
                $y2 = $halfHeight + $gap;

                return [
                    ['x' => $x1, 'y' => $y1, 'width' => $targetWidth1, 'height' => $targetHeight1, 'img' => $img1],
                    ['x' => $x2, 'y' => $y2, 'width' => $targetWidth2, 'height' => $targetHeight2, 'img' => $img2],
                ];

            case 'overlapping':
                $targetWidth1 = (int)($canvasWidth * 0.75);
                $targetHeight1 = (int)($img1['height'] * $targetWidth1 / $img1['width']);
                if ($targetHeight1 > $canvasHeight) {
                    $targetHeight1 = $canvasHeight;
                    $targetWidth1 = (int)($img1['width'] * $targetHeight1 / $img1['height']);
                }
                $x1 = (int)(($canvasWidth - $targetWidth1) / 2) - 30;
                $y1 = (int)(($canvasHeight - $targetHeight1) / 2) - 20;

                $targetWidth2 = (int)($canvasWidth * 0.5);
                $targetHeight2 = (int)($img2['height'] * $targetWidth2 / $img2['width']);
                if ($targetHeight2 > (int)($canvasHeight * 0.6)) {
                    $targetHeight2 = (int)($canvasHeight * 0.6);
                    $targetWidth2 = (int)($img2['width'] * $targetHeight2 / $img2['height']);
                }
                $x2 = (int)(($canvasWidth - $targetWidth2) / 2) + 30;
                $y2 = (int)(($canvasHeight - $targetHeight2) / 2) + 20;

                return [
                    ['x' => $x1, 'y' => $y1, 'width' => $targetWidth1, 'height' => $targetHeight1, 'img' => $img1],
                    ['x' => $x2, 'y' => $y2, 'width' => $targetWidth2, 'height' => $targetHeight2, 'img' => $img2],
                ];

            case 'diagonal':
                $targetWidth1 = (int)($canvasWidth * 0.55);
                $targetHeight1 = (int)($img1['height'] * $targetWidth1 / $img1['width']);
                if ($targetHeight1 > (int)($canvasHeight * 0.6)) {
                    $targetHeight1 = (int)($canvasHeight * 0.6);
                    $targetWidth1 = (int)($img1['width'] * $targetHeight1 / $img1['height']);
                }
                $x1 = 40;
                $y1 = 40;

                // Bottom-right image
                $targetWidth2 = (int)($canvasWidth * 0.55);
                $targetHeight2 = (int)($img2['height'] * $targetWidth2 / $img2['width']);
                if ($targetHeight2 > (int)($canvasHeight * 0.6)) {
                    $targetHeight2 = (int)($canvasHeight * 0.6);
                    $targetWidth2 = (int)($img2['width'] * $targetHeight2 / $img2['height']);
                }
                $x2 = $canvasWidth - $targetWidth2 - 40;
                $y2 = $canvasHeight - $targetHeight2 - 40;

                return [
                    ['x' => $x1, 'y' => $y1, 'width' => $targetWidth1, 'height' => $targetHeight1, 'img' => $img1],
                    ['x' => $x2, 'y' => $y2, 'width' => $targetWidth2, 'height' => $targetHeight2, 'img' => $img2],
                ];
        }

        return [];
    }

    /**
     * Draw two images on canvas
     */
    private function drawTwoImages($canvas, array $positions): void
    {
        $framePadding = 3;
        foreach ($positions as $pos) {
            if (isset($pos['img'])) {
                $img = $pos['img'];
                $imageX = $pos['x'] + $framePadding;
                $imageY = $pos['y'] + $framePadding;
                $imageWidth = $pos['width'] - ($framePadding * 2);
                $imageHeight = $pos['height'] - ($framePadding * 2);

                imagecopyresampled($canvas, $img['resource'], $imageX, $imageY, 0, 0, $imageWidth, $imageHeight, $img['width'], $img['height']);
            }
        }
    }

    /**
     * Calculate multiple images layout positions (professional L-shape or grid)
     */
    private function calculateMultipleImagesLayout(array $images, int $canvasWidth, int $canvasHeight, int $variationIndex): array
    {
        $imageCount = count($images);
        $positions = [];

        if ($imageCount === 3 && $variationIndex % 2 === 0) {
            $img1 = $images[0];
            $targetWidth1 = (int)($canvasWidth * 0.48);
            $targetHeight1 = (int)($img1['height'] * $targetWidth1 / $img1['width']);
            if ($targetHeight1 > (int)($canvasHeight * 0.5)) {
                $targetHeight1 = (int)($canvasHeight * 0.5);
                $targetWidth1 = (int)($img1['width'] * $targetHeight1 / $img1['height']);
            }
            $positions[] = ['x' => 30, 'y' => 30, 'width' => $targetWidth1, 'height' => $targetHeight1, 'img' => $img1];

            $img2 = $images[1];
            $targetWidth2 = (int)($canvasWidth * 0.45);
            $targetHeight2 = (int)($img2['height'] * $targetWidth2 / $img2['width']);
            if ($targetHeight2 > (int)($canvasHeight * 0.48)) {
                $targetHeight2 = (int)($canvasHeight * 0.48);
                $targetWidth2 = (int)($img2['width'] * $targetHeight2 / $img2['height']);
            }
            $positions[] = ['x' => 30 + $targetWidth1 + 20, 'y' => 30, 'width' => $targetWidth2, 'height' => $targetHeight2, 'img' => $img2];

            $img3 = $images[2];
            $targetWidth3 = (int)($canvasWidth * 0.48);
            $targetHeight3 = (int)($img3['height'] * $targetWidth3 / $img3['width']);
            if ($targetHeight3 > (int)($canvasHeight * 0.45)) {
                $targetHeight3 = (int)($canvasHeight * 0.45);
                $targetWidth3 = (int)($img3['width'] * $targetHeight3 / $img3['height']);
            }
            $positions[] = ['x' => 30, 'y' => 30 + $targetHeight1 + 20, 'width' => $targetWidth3, 'height' => $targetHeight3, 'img' => $img3];

            return $positions;
        }

        $cols = $imageCount <= 4 ? 2 : 3;
        $rows = (int)ceil($imageCount / $cols);
        $gap = 15;
        $cellWidth = (int)(($canvasWidth - ($gap * ($cols + 1))) / $cols);
        $cellHeight = (int)(($canvasHeight - ($gap * ($rows + 1))) / $rows);

        foreach ($images as $index => $img) {
            $col = $index % $cols;
            $row = (int)($index / $cols);

            $x = $gap + ($col * ($cellWidth + $gap));
            $y = $gap + ($row * ($cellHeight + $gap));

            $padding = 10;
            $targetWidth = $cellWidth - ($padding * 2);
            $targetHeight = (int)($img['height'] * $targetWidth / $img['width']);
            if ($targetHeight > $cellHeight - ($padding * 2)) {
                $targetHeight = $cellHeight - ($padding * 2);
                $targetWidth = (int)($img['width'] * $targetHeight / $img['height']);
            }

            $x += (int)(($cellWidth - $targetWidth) / 2);
            $y += (int)(($cellHeight - $targetHeight) / 2);

            $positions[] = ['x' => $x, 'y' => $y, 'width' => $targetWidth, 'height' => $targetHeight, 'img' => $img];
        }

        return $positions;
    }

    /**
     * Draw multiple images on canvas
     */
    private function drawMultipleImages($canvas, array $positions): void
    {
        $framePadding = 3;
        foreach ($positions as $pos) {
            if (isset($pos['img'])) {
                $img = $pos['img'];
                $imageX = $pos['x'] + $framePadding;
                $imageY = $pos['y'] + $framePadding;
                $imageWidth = $pos['width'] - ($framePadding * 2);
                $imageHeight = $pos['height'] - ($framePadding * 2);

                imagecopyresampled($canvas, $img['resource'], $imageX, $imageY, 0, 0, $imageWidth, $imageHeight, $img['width'], $img['height']);
            }
        }
    }

    /**
     * Add text overlays to canvas with professional styling
     */
    private function addTextOverlays($canvas, array $textElements, int $canvasWidth, int $canvasHeight, int $variationIndex): void
    {
        if (empty($textElements)) {
            return;
        }

        $textCategories = $this->categorizeTextElements($textElements);
        $layoutStrategy = $variationIndex % 4;
        $yStart = 0;
        $xStart = 0;
        $spacing = 15;

        switch ($layoutStrategy) {
            case 0:
                $xStart = (int)($canvasWidth * 0.65);
                $yStart = (int)($canvasHeight * 0.4);
                break;
            case 1:
                $xStart = (int)($canvasWidth * 0.6);
                $yStart = 50;
                break;
            case 2:
                $xStart = (int)($canvasWidth * 0.55);
                $yStart = (int)($canvasHeight * 0.6);
                break;
            case 3:
                $xStart = (int)($canvasWidth * 0.58);
                $yStart = (int)($canvasHeight * 0.3);
                break;
        }

        $currentY = $yStart;

        foreach ($textElements as $index => $text) {
            $isImportant = $this->isImportantText($text, $textCategories);
            $font = $isImportant ? 5 : 5;
            $fontMultiplier = $isImportant ? 1.4 : 1.0;

            $textWidth = imagefontwidth($font) * strlen($text);
            $textHeight = imagefontheight($font);

            $padding = $isImportant ? 20 : 15;
            $boxWidth = (int)($textWidth * $fontMultiplier) + ($padding * 2);
            $boxHeight = (int)($textHeight * $fontMultiplier) + ($padding * 2);

            $boxX = $xStart;
            $boxY = $currentY;

            $shadowOffset = 6;
            $shadowBlur = 8;
            for ($i = 0; $i < $shadowBlur; $i++) {
                $alpha = (int)(80 - ($i * 9));
                if ($alpha < 0) $alpha = 0;
                $shadowColor = imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
                $offset = $shadowOffset - ($i * 0.7);
                imagefilledrectangle(
                    $canvas,
                    $boxX + $offset,
                    $boxY + $offset,
                    $boxX + $boxWidth + $offset,
                    $boxY + $boxHeight + $offset,
                    $shadowColor
                );
            }

            $bgColor = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, $boxX, $boxY, $boxX + $boxWidth, $boxY + $boxHeight, $bgColor);

            $borderColor = imagecolorallocate($canvas, 0, 0, 0);
            $borderWidth = 2;

            imagerectangle($canvas, $boxX, $boxY, $boxX + $boxWidth, $boxY + $boxHeight, $borderColor);
            imagerectangle($canvas, $boxX + 1, $boxY + 1, $boxX + $boxWidth - 1, $boxY + $boxHeight - 1, $borderColor);

            $textX = $boxX + $padding;
            $textY = $boxY + $padding + (int)($textHeight * ($fontMultiplier - 1) / 2);

            $textColor = imagecolorallocate($canvas, 0, 0, 0);

            if ($isImportant) {
                for ($i = 0; $i < 2; $i++) {
                    imagestring($canvas, $font, $textX + $i, $textY + $i, $text, $textColor);
                }
            } else {
                imagestring($canvas, $font, $textX, $textY, $text, $textColor);
            }

            $currentY += $boxHeight + $spacing;
        }
    }

    /**
     * Categorize text elements by importance
     */
    private function categorizeTextElements(array $textElements): array
    {
        $categories = [
            'important' => [], // Discounts, percentages, key offers
            'normal' => [],    // Regular text
        ];

        foreach ($textElements as $text) {
            // Check if text contains discount/percentage (high importance)
            if (preg_match('/\d+\s*%|off|discount|sale|special/i', $text)) {
                $categories['important'][] = $text;
            } else {
                $categories['normal'][] = $text;
            }
        }

        return $categories;
    }

    /**
     * Check if text is important (should be larger)
     */
    private function isImportantText(string $text, array $categories): bool
    {
        return in_array($text, $categories['important']);
    }


    /**
     * Group text elements into logical blocks (same size within blocks)
     */
    private function groupTextIntoBlocks(array $textElements): array
    {
        if (empty($textElements)) {
            return [];
        }

        $blocks = [];
        foreach ($textElements as $element) {
            $blocks[] = [$element];
        }

        return $blocks;
    }
}

