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
Route::get('/storage/{path}', function ($path) {
    $filePath = storage_path('app/public/' . $path);
    
    if (!file_exists($filePath)) {
        abort(404);
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
    
    return response()->file($filePath, [
        'Content-Type' => $mimeType,
        'Cache-Control' => 'public, max-age=31536000',
    ]);
})->where('path', '.*');
