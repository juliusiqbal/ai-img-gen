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
                    <button @click="downloadAll()" 
                            :disabled="templates.length === 0"
                            class="btn btn-success btn-sm">
                        Download All
                    </button>
                    <button x-show="selectedCategoryId" 
                            @click="downloadCategoryTemplates()"
                            class="btn btn-info btn-sm">
                        Download Category
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
                            <div class="d-flex flex-column gap-2 mb-2">
                                <div class="d-flex gap-1">
                                    <button @click="previewTemplate(template)" class="btn btn-info btn-sm flex-fill">Preview</button>
                                    <a :href="`/api/templates/${template.id}/download`" 
                                       class="btn btn-primary btn-sm flex-fill">Download</a>
                                </div>
                                <div class="d-flex gap-1">
                                    <button @click="regenerateTemplate(template.id)" 
                                            :disabled="regenerating === template.id"
                                            class="btn btn-warning btn-sm flex-fill">
                                        <span x-show="regenerating !== template.id">Regenerate</span>
                                        <span x-show="regenerating === template.id">Generating...</span>
                                    </button>
                                    <input type="checkbox" 
                                           :value="template.id" 
                                           x-model="selectedTemplates" 
                                           class="form-check-input mt-0">
                                </div>
                            </div>

                            <!-- Template Info -->
                            <div class="small text-muted">
                                <div x-show="template.project_name" class="fw-bold text-primary mb-1" x-text="template.project_name"></div>
                                <div class="text-truncate" x-text="template.svg_path" title="template.svg_path"></div>
                                <div x-show="template.printing_dimensions && template.printing_dimensions.width" class="mt-1">
                                    <span x-text="template.printing_dimensions ? `${template.printing_dimensions.width} × ${template.printing_dimensions.height} ${template.printing_dimensions.unit || 'mm'}` : ''"></span>
                                </div>
                                <div x-show="template.generation_prompt" class="mt-1">
                                    <button @click="showPrompt = template.id" class="btn btn-link btn-sm p-0 text-decoration-none">View Prompt</button>
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
        previewTemplateData: null,
        showPrompt: null,
        regenerating: null,

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
        },

        previewTemplate(template) {
            this.previewTemplateData = template;
        },

        closePreview() {
            this.previewTemplateData = null;
        },

        async regenerateTemplate(templateId) {
            if (!confirm('Regenerate this template? This will create a new template.')) {
                return;
            }

            this.regenerating = templateId;
            try {
                const response = await fetch(`/api/generate/regenerate/${templateId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();
                if (response.ok) {
                    alert('Template regenerated successfully!');
                    await this.loadTemplates();
                } else {
                    alert('Failed to regenerate: ' + (data.message || data.error));
                }
            } catch (e) {
                alert('Error regenerating template: ' + e.message);
            } finally {
                this.regenerating = null;
            }
        },

        async downloadAll() {
            try {
                const response = await fetch('/api/templates/download-all', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({})
                });

                if (response.ok) {
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'all_templates.zip';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                } else {
                    alert('Failed to download all templates');
                }
            } catch (e) {
                alert('Error downloading templates: ' + e.message);
            }
        },
    }
}
</script>

<!-- Preview Modal -->
<div x-show="previewTemplateData" 
     x-cloak
     class="modal fade show" 
     style="display: block; background: rgba(0,0,0,0.5);"
     @click.self="closePreview()"
     @keydown.escape.window="closePreview()">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Template Preview</h5>
                <button type="button" class="btn-close" @click="closePreview()"></button>
            </div>
            <div class="modal-body text-center">
                <template x-if="previewTemplateData">
                    <div>
                        <img :src="`${baseUrl}/storage/${previewTemplateData.svg_path}`" 
                             alt="Template Preview" 
                             class="img-fluid" 
                             style="max-height: 70vh;" />
                        <div class="mt-3" x-show="previewTemplateData.generation_prompt">
                            <strong>Generation Prompt:</strong>
                            <p class="small text-muted" x-text="previewTemplateData.generation_prompt"></p>
                        </div>
                    </div>
                </template>
            </div>
            <div class="modal-footer">
                <a :href="`/api/templates/${previewTemplateData?.id}/download`" 
                   class="btn btn-primary">Download</a>
                <button type="button" class="btn btn-secondary" @click="closePreview()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Prompt View Modal -->
<div x-show="showPrompt" 
     x-cloak
     class="modal fade show" 
     style="display: block; background: rgba(0,0,0,0.5);"
     @click.self="showPrompt = null"
     @keydown.escape.window="showPrompt = null">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Generation Prompt</h5>
                <button type="button" class="btn-close" @click="showPrompt = null"></button>
            </div>
            <div class="modal-body">
                <template x-for="template in templates" :key="template.id">
                    <div x-show="showPrompt === template.id">
                        <p class="small" x-text="template.generation_prompt"></p>
                    </div>
                </template>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" @click="showPrompt = null">Close</button>
            </div>
        </div>
    </div>
</div>
@endpush
@endsection

