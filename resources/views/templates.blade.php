@extends('layouts.app')

@section('title', 'All Templates - AI Image Generator')

@section('content')
<div class="container py-4">
    <h1 class="h3 fw-bold mb-4">All Templates</h1>

    <div x-data="templatesPage()" x-init="init()" class="bg-white border rounded p-3">
        <!-- Success Message -->
        <div x-show="showSuccess" x-transition class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <strong>Success!</strong> <span x-text="successMessage"></span>
            <button type="button" class="btn-close" @click="showSuccess = false" aria-label="Close"></button>
        </div>
        
        <!-- Category Filter -->
        <div class="mb-3">
            <label class="form-label">Filter by Category</label>
            <select x-model="selectedCategoryId" @change="filterTemplates()" class="form-select">
                <option value="">All Categories</option>
                <template x-for="cat in categories" :key="cat.id">
                    <option :value="cat.id" x-text="cat.name + ' (' + cat.templates_count + ')'"></option>
                </template>
            </select>
        </div>

        <!-- Loading State -->
        <div x-show="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted mt-2">Loading templates...</p>
        </div>

        <!-- No Templates Message -->
        <div x-show="!loading && templates.length === 0" class="text-center py-5">
            <p class="text-muted">No templates found. <a href="{{ url('/generate') }}">Generate some templates</a> to get started!</p>
        </div>

        <!-- Templates Grid -->
        <div x-show="!loading && templates.length > 0">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h2 class="h5 fw-bold mb-0">
                    Templates 
                    <span class="text-muted" x-text="'(' + templates.length + ')'"></span>
                </h2>
                <div class="d-flex gap-2">
                    <button @click="downloadSelected()" :disabled="selectedTemplates.length === 0" 
                            class="btn btn-primary btn-sm">
                        Download Selected (<span x-text="selectedTemplates.length"></span>)
                    </button>
                    <button x-show="selectedCategoryId" 
                            :href="`/api/categories/${selectedCategoryId}/download`" 
                            @click="downloadCategoryTemplates()"
                            class="btn btn-success btn-sm">
                        Download All in Category
                    </button>
                </div>
            </div>

            <div class="row g-3">
                <template x-for="template in templates" :key="template.id">
                    <div class="col-md-3">
                        <div class="border rounded p-3 bg-light h-100">
                            <!-- Template Preview -->
                            <div class="border rounded p-2 bg-white mb-2 text-center" 
                                 style="height:200px; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                <img :src="`${baseUrl}/storage/${template.svg_path}`" 
                                     alt="Template" 
                                     class="img-fluid" 
                                     style="max-height:180px; object-fit:contain;" />
                            </div>

                            <!-- Category Badge -->
                            <div class="mb-2">
                                <span class="badge bg-secondary" x-text="template.category ? template.category.name : 'Uncategorized'"></span>
                            </div>

                            <!-- Action Buttons -->
                            <div class="d-flex gap-2 align-items-center mb-2">
                                <a :href="`/api/templates/${template.id}/download`" 
                                   class="btn btn-primary btn-sm flex-fill">Download</a>
                                <input type="checkbox" 
                                       :value="template.id" 
                                       x-model="selectedTemplates" 
                                       class="form-check-input mt-0">
                            </div>

                            <!-- Template Info -->
                            <div class="small text-muted">
                                <div class="text-truncate" x-text="template.svg_path" title="template.svg_path"></div>
                                <div x-show="template.printing_dimensions && template.printing_dimensions.width" class="mt-1">
                                    <span x-text="template.printing_dimensions ? `${template.printing_dimensions.width} × ${template.printing_dimensions.height} ${template.printing_dimensions.unit || 'mm'}` : ''"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function templatesPage() {
    return {
        categories: [],
        templates: [],
        selectedCategoryId: '',
        selectedTemplates: [],
        loading: false,
        baseUrl: window.location.origin,
        showSuccess: false,
        successMessage: '',

        async init() {
            // Check for category_id in URL first
            const urlParams = new URLSearchParams(window.location.search);
            const categoryIdParam = urlParams.get('category_id');
            if (categoryIdParam) {
                this.selectedCategoryId = categoryIdParam;
            }
            
            // Check for success message in URL
            if (urlParams.get('success') === '1') {
                const count = urlParams.get('count') || '0';
                this.successMessage = `Successfully generated ${count} template${count !== '1' ? 's' : ''}!`;
                this.showSuccess = true;
                
                // Remove success parameter from URL without reload, keep category_id if present
                let newUrl = window.location.pathname;
                if (this.selectedCategoryId) {
                    newUrl += `?category_id=${this.selectedCategoryId}`;
                }
                window.history.replaceState({}, '', newUrl);
                
                // Auto-hide after 5 seconds
                setTimeout(() => {
                    this.showSuccess = false;
                }, 5000);
            }
            
            await this.loadCategories();
            await this.loadTemplates();
        },

        async loadCategories() {
            try {
                const response = await fetch('/api/categories', {
                    headers: { 'Accept': 'application/json' }
                });
                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    this.categories = await response.json();
                }
            } catch (e) {
                console.error('Failed to load categories:', e);
            }
        },

        async loadTemplates() {
            this.loading = true;
            try {
                let url = '/api/templates';
                if (this.selectedCategoryId) {
                    url += `?category_id=${this.selectedCategoryId}`;
                }

                const response = await fetch(url, {
                    headers: { 'Accept': 'application/json' }
                });
                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    this.templates = await response.json();
                } else {
                    const text = await response.text();
                    console.error('Failed to load templates:', text.slice(0, 300));
                    this.templates = [];
                }
            } catch (e) {
                console.error('Failed to load templates:', e);
                this.templates = [];
            } finally {
                this.loading = false;
            }
        },

        filterTemplates() {
            this.selectedTemplates = [];
            this.loadTemplates();
        },

        async downloadSelected() {
            if (this.selectedTemplates.length === 0) return;

            const formData = new FormData();
            this.selectedTemplates.forEach(id => {
                formData.append('template_ids[]', id);
            });

            const response = await fetch('/api/templates/download-batch', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: formData
            });

            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'templates.zip';
                a.click();
            }
        },

        downloadCategoryTemplates() {
            if (!this.selectedCategoryId) return;
            window.location.href = `/api/categories/${this.selectedCategoryId}/download`;
        }
    }
}
</script>
@endpush
@endsection

