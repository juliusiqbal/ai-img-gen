@extends('layouts.app')

@section('title', 'Generate Templates - AI Image Generator')

@section('content')
<div class="container py-4">
    <h1 class="h3 fw-bold mb-4">Generate SVG Templates</h1>

    <div x-data="generatorForm()" x-init="init()" class="bg-white border rounded p-3">
        <!-- Category Selection -->
        <div class="mb-6">
            <label class="form-label">Category</label>
            <div class="row g-3">
                <div class="col-md-6">
                    <select x-model="selectedCategoryId" @change="if(selectedCategoryId) { categoryName = ''; }" class="form-select">
                        <option value="">Select existing category</option>
                        <template x-for="cat in categories" :key="cat.id">
                            <option :value="cat.id" x-text="cat.name"></option>
                        </template>
                    </select>
                </div>
                <div class="col-md-6">
                    <input type="text" x-model="categoryName" @input="if(categoryName) { selectedCategoryId = ''; }" placeholder="Or enter new category name" class="form-control">
                </div>
            </div>
        </div>

        <!-- Image Upload -->
        <div class="mb-6">
            <label class="form-label">Sample Image (Optional)</label>
            <div class="border border-2 border-secondary rounded p-4 text-center" 
                 @drop.prevent="handleFileDrop($event)"
                 @dragover.prevent="$el.classList.add('border-indigo-500')"
                 @dragleave.prevent="$el.classList.remove('border-indigo-500')">
                <input type="file" @change="handleFileSelect($event)" accept="image/*" class="d-none" x-ref="fileInput" data-upload-input>
                <div x-show="!uploadedImage" @click="openFilePicker()" class="cursor-pointer">
                    <svg class="mx-auto" style="height:48px;width:48px;color:#6c757d" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <p class="mt-2 text-muted small">Click to upload or drag and drop</p>
                </div>
                <template x-if="uploadedImage">
                    <div class="mt-4">
                        <img :src="uploadedImage.previewUrl || uploadedImage.url" :alt="uploadedImage.name || 'Uploaded image'" class="img-fluid" style="max-height:200px">
                        <button @click="uploadedImage = null" class="btn btn-link text-danger p-0 mt-2">Remove</button>
                    </div>
                </template>
            </div>
        </div>

        <!-- Printing Dimensions -->
        <div class="mb-6">
            <label class="form-label">Printing Dimensions (Optional)</label>
            <div class="row g-3">
                <div class="col-md-4">
                    <select x-model="standardSize" @change="if(standardSize) { width = ''; height = ''; }" class="form-select">
                        <option value="">Custom</option>
                        <option value="A4">A4 (210 × 297 mm)</option>
                        <option value="A3">A3 (297 × 420 mm)</option>
                        <option value="A2">A2 (420 × 594 mm)</option>
                        <option value="A5">A5 (148 × 210 mm)</option>
                        <option value="Letter">Letter (216 × 279 mm)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="number" x-model="width" placeholder="Width" :disabled="!!standardSize" class="form-control">
                </div>
                <div class="col-md-4">
                    <input type="number" x-model="height" placeholder="Height" :disabled="!!standardSize" class="form-control">
                </div>
            </div>
            <select x-model="unit" class="form-select mt-2" style="max-width:200px">
                <option value="mm">mm</option>
                <option value="cm">cm</option>
                <option value="inches">inches</option>
            </select>
        </div>

        <!-- Template Count -->
        <div class="mb-3">
            <label class="form-label">Number of Templates</label>
            <input type="number" x-model="templateCount" min="1" max="10" value="4" class="form-control" style="max-width:150px">
        </div>

        <!-- Submit Button -->
        <button @click="generateTemplates()" :disabled="loading || (!selectedCategoryId && !categoryName)" class="btn btn-primary w-100">
            <span x-show="!loading">Generate Templates</span>
            <span x-show="loading">Generating...</span>
        </button>

        <!-- Error Message -->
        <div x-show="error" class="mt-4 p-4 bg-red-50 border border-red-300 rounded-lg">
            <div class="flex items-start">
                <svg class="h-5 w-5 text-red-600 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold text-red-800 mb-1">Error</h3>
                    <p class="text-sm text-red-700 whitespace-pre-line" x-text="error"></p>
                </div>
                <button @click="error = null" class="text-red-600 hover:text-red-800 ml-4">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Generated Templates -->
    <div x-show="generatedTemplates.length > 0" class="mt-4">
       
        <div class="row g-3">
            <template x-for="(template, index) in generatedTemplates" :key="template.id">
                <div class="col-md-3">
                    <div class="border rounded p-3 bg-light text-center" style="height:200px; display:flex; align-items:center; justify-content:center;">
                        <img :src="`${baseUrl}/storage/${template.svg_path}`" alt="Template" class="img-fluid" style="max-height:180px; object-fit:contain;" />
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

@push('scripts')
<script>
function generatorForm() {
    return {
        categories: [],
        baseUrl: window.location.origin,
        selectedCategoryId: '',
        categoryName: '',
        uploadedImage: null,
        standardSize: '',
        width: '',
        height: '',
        unit: 'mm',
        templateCount: 4,
        loading: false,
        error: null,
        generatedTemplates: [],

        async init() {
            try {
                const response = await fetch('/api/categories', {
                    headers: { 'Accept': 'application/json' }
                });
                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    this.categories = await response.json();
                } else {
                    const text = await response.text();
                    throw new Error(text.slice(0, 300));
                }
            } catch (e) {
                this.error = 'Failed to load categories: ' + e.message;
            }
        },

        openFilePicker() {
            try {
                if (this.$refs && this.$refs.fileInput) {
                    this.$refs.fileInput.click();
                    return;
                }
            } catch (e) {}
            const fallback = document.querySelector('[data-upload-input]');
            if (fallback) fallback.click();
        },

        handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                // Show local preview immediately to avoid any server 403 issues
                const previewUrl = URL.createObjectURL(file);
                this.uploadedImage = {
                    url: '',
                    previewUrl,
                    path: '',
                    name: file.name,
                    file: file
                };
                this.uploadFile(file);
            }
        },

        handleFileDrop(event) {
            const file = event.dataTransfer.files[0];
            if (file && file.type.startsWith('image/')) {
                this.uploadFile(file);
            }
        },

        async uploadFile(file) {
            const formData = new FormData();
            formData.append('image', file);

            try {
                const response = await fetch('/api/upload', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    const data = await response.json();
                    if (response.ok) {
                        // Keep existing previewUrl; set server URL/path for later use
                        this.uploadedImage = Object.assign({}, this.uploadedImage || {}, {
                            url: data.url,
                            path: data.path
                        });
                    } else {
                        this.error = data.errors ? data.errors.join(', ') : (data.message || 'Upload failed');
                    }
                } else {
                    const text = await response.text();
                    throw new Error(text.slice(0, 500));
                }
            } catch (error) {
                this.error = 'Upload failed: ' + error.message;
            }
        },

        async generateTemplates() {
            this.loading = true;
            this.error = null;
            this.generatedTemplates = [];
            this.selectedTemplates = [];

            const formData = new FormData();
            
            if (this.selectedCategoryId) {
                formData.append('category_id', this.selectedCategoryId);
            } else if (this.categoryName) {
                formData.append('category_name', this.categoryName);
            }

            if (this.uploadedImage && this.uploadedImage.path) {
                // If we have a path from previous upload, we can reference it
                // But for fresh uploads, we need the file
                if (this.uploadedImage.file) {
                    formData.append('image', this.uploadedImage.file);
                }
            }

            if (this.standardSize) {
                formData.append('standard_size', this.standardSize);
            } else if (this.width && this.height) {
                formData.append('width', this.width);
                formData.append('height', this.height);
                formData.append('unit', this.unit);
            }

            formData.append('template_count', this.templateCount);

            try {
                const response = await fetch('/api/generate', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    const data = await response.json();
                    if (response.ok) {
                        // Ensure selected category is set to returned category
                        if (data.category && data.category.id) {
                            this.selectedCategoryId = String(data.category.id);
                        }

                        // Show newly generated templates immediately
                        this.generatedTemplates = Array.isArray(data.templates) ? data.templates : [];

                        // If category was newly created, refresh categories list
                        if (!this.selectedCategoryId && this.categoryName) {
                            await this.init();
                        }
                    } else {
                        let errorMsg = data.message || data.error || 'Generation failed';
                        if (errorMsg.includes('billing limit') || errorMsg.includes('quota') || response.status === 402) {
                            errorMsg += '\n\nTo fix this:\n1. Visit https://platform.openai.com/account/billing\n2. Add credits to your OpenAI account\n3. Or set a higher billing limit';
                        } else if (errorMsg.includes('API key') || response.status === 401) {
                            errorMsg += '\n\nTo fix this:\n1. Check your OPENAI_API_KEY in the .env file\n2. Make sure the API key is valid and active';
                        }
                        this.error = errorMsg;
                    }
                } else {
                    const text = await response.text();
                    throw new Error(text.slice(0, 500));
                }
            } catch (error) {
                this.error = 'Generation failed: ' + error.message;
            } finally {
                this.loading = false;
            }
        }
    }
}
</script>
@endpush
@endsection

