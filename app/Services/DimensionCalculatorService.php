<?php

namespace App\Services;

class DimensionCalculatorService
{
    /**
     * Standard printing sizes in millimeters
     */
    private array $standardSizes = [
        'A4' => ['width' => 210, 'height' => 297],
        'A3' => ['width' => 297, 'height' => 420],
        'A2' => ['width' => 420, 'height' => 594],
        'A5' => ['width' => 148, 'height' => 210],
        'Letter' => ['width' => 216, 'height' => 279],
        'Legal' => ['width' => 216, 'height' => 356],
    ];

    /**
     * Calculate aspect ratio from dimensions
     */
    public function calculateAspectRatio(float $width, float $height): float
    {
        if ($height == 0) {
            return 1;
        }
        return $width / $height;
    }

    /**
     * Get standard size dimensions
     */
    public function getStandardSize(string $size): ?array
    {
        return $this->standardSizes[strtoupper($size)] ?? null;
    }

    /**
     * Calculate SVG viewBox dimensions based on printing dimensions
     */
    public function calculateSVGViewBox(array $printingDimensions, ?float $aspectRatio = null): array
    {
        $width = $printingDimensions['width'] ?? 100;
        $height = $printingDimensions['height'] ?? 100;
        $unit = $printingDimensions['unit'] ?? 'mm';

        // Convert to pixels (assuming 96 DPI)
        // 1 inch = 25.4mm, 1 inch = 96 pixels
        $pixelsPerMM = 96 / 25.4;

        if ($unit === 'mm') {
            $svgWidth = $width * $pixelsPerMM;
            $svgHeight = $height * $pixelsPerMM;
        } elseif ($unit === 'cm') {
            $svgWidth = ($width * 10) * $pixelsPerMM;
            $svgHeight = ($height * 10) * $pixelsPerMM;
        } elseif ($unit === 'inches' || $unit === 'in') {
            $svgWidth = $width * 96;
            $svgHeight = $height * 96;
        } else {
            $svgWidth = $width;
            $svgHeight = $height;
        }

        if ($aspectRatio && $aspectRatio > 0) {
            if ($svgWidth / $svgHeight > $aspectRatio) {
                $svgHeight = $svgWidth / $aspectRatio;
            } else {
                $svgWidth = $svgHeight * $aspectRatio;
            }
        }

        return [
            'width' => round($svgWidth, 2),
            'height' => round($svgHeight, 2),
            'viewBox' => "0 0 {$svgWidth} {$svgHeight}",
        ];
    }

    /**
     * Convert dimensions between units
     */
    public function convertDimensions(float $value, string $fromUnit, string $toUnit): float
    {
        // Convert to millimeters first
        $mmValue = match ($fromUnit) {
            'mm' => $value,
            'cm' => $value * 10,
            'inches', 'in' => $value * 25.4,
            'pixels', 'px' => $value / (96 / 25.4),
            default => $value,
        };

        return match ($toUnit) {
            'mm' => $mmValue,
            'cm' => $mmValue / 10,
            'inches', 'in' => $mmValue / 25.4,
            'pixels', 'px' => $mmValue * (96 / 25.4),
            default => $mmValue,
        };
    }

    /**
     * Get all standard sizes
     */
    public function getStandardSizes(): array
    {
        return $this->standardSizes;
    }
}


