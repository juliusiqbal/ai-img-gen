<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories.
     */
    public function index(): JsonResponse
    {
        $categories = Category::withCount('templates')->orderBy('name')->get();
        return response()->json($categories);
    }

    /**
     * Store a newly created category or return existing one.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::firstOrCreate(
            ['name' => $request->name],
            ['description' => $request->description]
        );

        return response()->json($category, 201);
    }

    /**
     * Display the specified category.
     */
    public function show(string $id): JsonResponse
    {
        $category = Category::with('templates')->findOrFail($id);
        return response()->json($category);
    }

    /**
     * Get templates by category
     */
    public function templates(string $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $templates = $category->templates()->latest()->get();
        return response()->json($templates);
    }
}
