@extends('layouts.app')

@section('title', 'Home - AI Image Generator')

@section('content')
<div class="container py-5">
    <div class="text-center">
        <h1 class="display-6 fw-bold mb-3">AI Image Generator</h1>
        <p class="lead text-muted mb-4">Generate professional SVG templates with AI</p>
        <a href="{{ url('/generate') }}" class="btn btn-primary btn-lg">Start Generating</a>
    </div>

    <div class="mt-5">
        <h2 class="h4 fw-bold mb-3">Features</h2>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="bg-white border rounded p-3 h-100">
                    <h3 class="h6 fw-semibold mb-2">Category Management</h3>
                    <p class="text-muted small mb-0">Create or select categories for your templates</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bg-white border rounded p-3 h-100">
                    <h3 class="h6 fw-semibold mb-2">AI-Powered Generation</h3>
                    <p class="text-muted small mb-0">Generate multiple template variations using GPT Image 1</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bg-white border rounded p-3 h-100">
                    <h3 class="h6 fw-semibold mb-2">SVG Export</h3>
                    <p class="text-muted small mb-0">Download templates as SVG files for printing</p>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-5" x-data="{ categories: [], loading: true }" x-init="
        fetch('/api/categories', { headers: { 'Accept': 'application/json' }})
            .then(res => res.json())
            .then(data => { categories = data; loading = false; })
            .catch(() => { loading = false; });
    ">
        <h2 class="h4 fw-bold mb-3">Categories</h2>
        <div x-show="loading" class="text-center py-4">
            <p class="text-muted">Loading categories...</p>
        </div>
        <div x-show="!loading && categories.length === 0" class="text-center py-4">
            <p class="text-muted">No categories yet. Create one when generating templates!</p>
        </div>
        <div x-show="!loading && categories.length > 0" class="row g-3">
            <template x-for="category in categories" :key="category.id">
                <div class="col-md-3">
                    <div class="bg-white border rounded p-3 h-100">
                        <h3 class="h6 fw-semibold mb-1" x-text="category.name"></h3>
                        <p class="small text-muted mb-0" x-text="category.templates_count + ' templates'"></p>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
@endsection