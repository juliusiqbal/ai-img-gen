<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    /**
     * Display all templates with optional category filter
     */
    public function index(Request $request): JsonResponse
    {
        $query = Template::with('category');

        if ($request->has('category_id') && $request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        $templates = $query->latest()->get();

        return response()->json($templates);
    }
}
