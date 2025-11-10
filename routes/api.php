<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\ImageUploadController;

// Categories
Route::get('/categories', [CategoryController::class, 'index']);
Route::post('/categories', [CategoryController::class, 'store']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);
Route::get('/categories/{id}/templates', [CategoryController::class, 'templates']);

// Templates
Route::get('/templates', [App\Http\Controllers\TemplateController::class, 'index']);

// Image Upload
Route::post('/upload', [ImageUploadController::class, 'upload']);

// Generation
Route::post('/generate', [GenerationController::class, 'generate']);
Route::post('/generate/preview-prompts', [GenerationController::class, 'previewPrompts']);
Route::post('/generate/regenerate/{id}', [GenerationController::class, 'regenerateTemplate']);
Route::get('/jobs/{id}', [GenerationController::class, 'jobStatus']);

// Downloads
Route::get('/templates/{id}/download', [DownloadController::class, 'downloadTemplate']);
Route::post('/templates/download-batch', [DownloadController::class, 'downloadBatch']);
Route::post('/templates/download-all', [DownloadController::class, 'downloadAll']);
Route::get('/categories/{categoryId}/download', [DownloadController::class, 'downloadCategory']);


