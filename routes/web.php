<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\GenerationController;

Route::get('/', function () {
    return view('home');
});

Route::get('/generate', function () {
    return view('generate');
});

Route::get('/templates', function () {
    return view('templates');
});

// Serve storage files for Windows/XAMPP compatibility
// This route must be placed before other routes to avoid conflicts
Route::get('/storage/{path}', function ($path) {
    // Sanitize the path to prevent directory traversal
    $path = str_replace('..', '', $path);
    $path = ltrim($path, '/');
    
    $filePath = storage_path('app/public/' . $path);
    
    // Check if file exists and is readable
    if (!file_exists($filePath)) {
        \Log::info('Storage file not found', [
            'requested_path' => $path,
            'full_path' => $filePath,
        ]);
        abort(404, 'File not found: ' . $path);
    }
    
    if (!is_readable($filePath)) {
        \Log::warning('Storage file not readable', [
            'path' => $path,
            'full_path' => $filePath,
        ]);
        abort(403, 'File not accessible');
    }
    
    // Determine MIME type
    $mimeType = mime_content_type($filePath);
    if (!$mimeType) {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $mimeTypes = [
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ];
        $mimeType = $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }
    
    // Set proper headers
    $headers = [
        'Content-Type' => $mimeType,
        'Cache-Control' => 'public, max-age=31536000',
    ];
    
    // For SVG files, ensure proper content type
    if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'svg') {
        $headers['Content-Type'] = 'image/svg+xml';
    }
    
    return response()->file($filePath, $headers);
})->where('path', '.*')->name('storage.local');
