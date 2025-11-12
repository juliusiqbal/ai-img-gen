<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class SVGConversionService
{
    /**
     * Convert raster image to SVG using Potrace
     */
    public function convertToSVGWithPotrace(string $imagePath, string $outputPath, array $options = []): bool
    {
        $fullImagePath = storage_path('app/public/' . $imagePath);
        $fullOutputPath = storage_path('app/public/' . $outputPath);

        if (!file_exists($fullImagePath)) {
            Log::error("Image file not found: {$fullImagePath}");
            return false;
        }

        // Check if potrace is available (Windows uses 'where', Unix uses 'which')
        $potraceCheck = new Process(['where', 'potrace']);
        $potraceCheck->run();

        if (!$potraceCheck->isSuccessful()) {
            // Try 'which' on Unix systems
            $potraceCheck = new Process(['which', 'potrace']);
            $potraceCheck->run();
        }

        if (!$potraceCheck->isSuccessful()) {
            // Fallback: create simple SVG wrapper
            return $this->createSimpleSVGWrapper($fullImagePath, $fullOutputPath, $options);
        }

        // First convert to PBM format (Potrace input)
        $pbmPath = str_replace('.svg', '.pbm', $fullOutputPath);

        try {
            // Use ImageMagick to convert to PBM
            $imagemagickProcess = new Process([
                'convert',
                $fullImagePath,
                '-threshold',
                '50%',
                $pbmPath
            ]);
            $imagemagickProcess->run();

            if (!file_exists($pbmPath)) {
                throw new \Exception('PBM conversion failed');
            }

            // Run Potrace
            $potraceArgs = [$pbmPath, '--svg', '--output', $fullOutputPath];

            if (isset($options['corner-threshold'])) {
                $potraceArgs[] = '--alphamax';
                $potraceArgs[] = $options['corner-threshold'];
            }

            $potraceProcess = new Process(['potrace', ...$potraceArgs]);
            $potraceProcess->run();

            // Clean up PBM file
            if (file_exists($pbmPath)) {
                unlink($pbmPath);
            }

            // Update SVG dimensions if provided
            if (isset($options['width']) && isset($options['height'])) {
                $this->updateSVGDimensions($fullOutputPath, $options['width'], $options['height']);
            }

            return file_exists($fullOutputPath);
        } catch (\Exception $e) {
            Log::error('Potrace conversion failed: ' . $e->getMessage());
            // Fallback to simple SVG wrapper
            return $this->createSimpleSVGWrapper($fullImagePath, $fullOutputPath, $options);
        }
    }

    /**
     * Create a simple SVG wrapper that embeds the raster image
     */
    public function createSimpleSVGWrapper(string $imagePath, string $outputPath, array $options = []): bool
    {
        $width = $options['width'] ?? 800;
        $height = $options['height'] ?? 600;

        // Get image base64 data
        $imageData = file_get_contents($imagePath);
        $base64 = base64_encode($imageData);
        $mimeType = mime_content_type($imagePath);
        $dataUri = 'data:' . $mimeType . ';base64,' . $base64;

        $svg = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%s" height="%s" viewBox="0 0 %s %s">
  <image x="0" y="0" width="%s" height="%s" xlink:href="%s" preserveAspectRatio="xMidYMid meet"/>
</svg>',
            $width,
            $height,
            $width,
            $height,
            $width,
            $height,
            htmlspecialchars($dataUri, ENT_XML1 | ENT_COMPAT, 'UTF-8')
        );

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, $svg);
        return file_exists($outputPath);
    }

    /**
     * Update SVG dimensions and viewBox
     */
    public function updateSVGDimensions(string $svgPath, float $width, float $height): bool
    {
        if (!file_exists($svgPath)) {
            return false;
        }

        $svgContent = file_get_contents($svgPath);

        // Update width and height attributes
        $svgContent = preg_replace(
            '/width="[^"]*"/',
            'width="' . $width . '"',
            $svgContent
        );

        $svgContent = preg_replace(
            '/height="[^"]*"/',
            'height="' . $height . '"',
            $svgContent
        );

        // Update viewBox
        $viewBox = "0 0 {$width} {$height}";
        $svgContent = preg_replace(
            '/viewBox="[^"]*"/',
            'viewBox="' . $viewBox . '"',
            $svgContent
        );

        // If viewBox doesn't exist, add it after width/height
        if (strpos($svgContent, 'viewBox') === false) {
            $svgContent = preg_replace(
                '/(<svg[^>]*height="[^"]*")/',
                '$1 viewBox="' . $viewBox . '"',
                $svgContent
            );
        }

        file_put_contents($svgPath, $svgContent);
        return true;
    }

    /**
     * Optimize SVG file
     */
    public function optimizeSVG(string $svgPath): bool
    {
        // Basic optimization - remove comments and whitespace
        $content = file_get_contents($svgPath);

        // Remove XML comments
        $content = preg_replace('/<!--.*?-->/s', '', $content);

        // Remove extra whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/>\s+</', '><', $content);

        file_put_contents($svgPath, trim($content));
        return true;
    }
}

