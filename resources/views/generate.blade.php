@extends('layouts.app')

@section('title', 'Generate Templates - AI Image Generator')

@section('content')
<div class="container py-4">
    <h1 class="h3 fw-bold mb-4">Generate SVG Templates</h1>

    <div x-data="generatorForm()" x-init="init()" class="bg-white border rounded p-3">
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

        <div class="mb-6">
            <label class="form-label">Sample Images (Optional - You can upload multiple images)</label>
            <div class="border border-2 border-secondary rounded p-4 text-center" 
                 @drop.prevent="handleFileDrop($event)"
                 @dragover.prevent="$el.classList.add('border-indigo-500')"
                 @dragleave.prevent="$el.classList.remove('border-indigo-500')">
                <input type="file" @change="handleFileSelect($event)" accept="image/*" multiple class="d-none" x-ref="fileInput" data-upload-input>
                <div x-show="uploadedImages.length === 0" @click="openFilePicker()" class="cursor-pointer">
                    <svg class="mx-auto" style="height:48px;width:48px;color:#6c757d" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <p class="mt-2 text-muted small">Click to upload or drag and drop (multiple images supported)</p>
                </div>
                <template x-if="uploadedImages.length > 0">
                    <div class="mt-4">
                        <div class="row g-2">
                            <template x-for="(img, index) in uploadedImages" :key="index">
                                <div class="col-md-3 col-6">
                                    <div class="position-relative">
                                        <img :src="img.previewUrl || img.url" :alt="img.name || 'Uploaded image'" class="img-fluid rounded" style="max-height:150px; width:100%; object-fit:cover;">
                                        <button @click="removeImage(index)" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1" style="padding: 2px 6px;">×</button>
                                    </div>
                                    <small class="text-muted d-block mt-1" x-text="img.name" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></small>
                                </div>
                            </template>
                        </div>
                        <button @click="uploadedImages = []" class="btn btn-link text-danger p-0 mt-2">Remove All</button>
                    </div>
                </template>
            </div>
        </div>

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

        <div class="mb-4 border rounded p-3 bg-light">
            <h5 class="mb-3">Design Preferences (Optional - For Structured Generation)</h5>
            
            <div class="mb-3">
                <label class="form-label">Project/Campaign Name</label>
                <input type="text" x-model="projectName" class="form-control" placeholder="e.g., Summer Sale Campaign">
            </div>

            <div class="mb-3">
                <label class="form-label">Template Type</label>
                <select x-model="templateType" class="form-select">
                    <option value="">Select template type</option>
                    <option value="poster">Poster</option>
                    <option value="banner">Banner</option>
                    <option value="brochure">Brochure</option>
                    <option value="postcard">Postcard</option>
                    <option value="flyer">Flyer</option>
                    <option value="social">Social Media Post</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Keywords</label>
                <input type="text" x-model="keywords" class="form-control" placeholder="e.g., plumbing, plumber, emergency service">
                <small class="text-muted">Comma-separated keywords describing your design. Text blocks will be automatically generated based on category and keywords.</small>
            </div>

            <div class="mb-3">
                <label class="form-label">Font Family</label>
                <select x-model="fontFamily" class="form-select">
                    <option value="">Default</option>
                    <option value="arial">Arial</option>
                    <option value="helvetica">Helvetica</option>
                    <option value="serif">Serif</option>
                    <option value="sans-serif">Sans-serif</option>
                    <option value="times">Times New Roman</option>
                    <option value="courier">Courier</option>
                </select>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Color Theme</label>
                    <select x-model="colorTheme" class="form-select">
                        <option value="">Default</option>
                        <option value="blue">Blue</option>
                        <option value="red">Red</option>
                        <option value="green">Green</option>
                        <option value="orange">Orange</option>
                        <option value="purple">Purple</option>
                        <option value="black">Black</option>
                        <option value="white">White</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Background Color</label>
                    <select x-model="backgroundColor" class="form-select">
                        <option value="">Default</option>
                        <option value="white">White</option>
                        <option value="light-gray">Light Gray</option>
                        <option value="light-blue">Light Blue</option>
                        <option value="light-green">Light Green</option>
                        <option value="cream">Cream</option>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <button type="button" @click="previewPrompts()" :disabled="loading || !canPreviewPrompts()" class="btn btn-outline-info">
                    👁️ Preview GPT-4 Prompts
                </button>
                <small class="text-muted d-block mt-1">See what prompts GPT-4 will generate before creating templates</small>
            </div>

            <div x-show="previewedPrompts.length > 0" class="mt-3 p-3 bg-white border rounded">
                <h6>Preview Prompts:</h6>
                <template x-for="(prompt, index) in previewedPrompts" :key="index">
                    <div class="mb-2 p-2 bg-light rounded">
                        <strong>Template <span x-text="index + 1"></span>:</strong>
                        <p class="mb-0 small" x-text="prompt"></p>
                    </div>
                </template>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Number of Templates</label>
            <input type="number" x-model="templateCount" min="1" max="10" value="1" class="form-control" style="max-width:150px">
        </div>

        <button @click="generateTemplates()" :disabled="loading || (!selectedCategoryId && !categoryName)" class="btn btn-primary w-100">
            <template x-if="!loading">
                <span>Generate Template</span>
            </template>
            <template x-if="loading">
                <span class="d-flex align-items-center justify-content-center">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Generating Template...
                </span>
            </template>
        </button>

        <div x-show="loading" class="mt-3">
            <div class="progress" style="height: 25px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                     role="progressbar" 
                     style="width: 100%"
                     aria-valuenow="100" 
                     aria-valuemin="0" 
                     aria-valuemax="100">
                    GPT-4 creating refined prompts → GPT Image 1 generating realistic template...
                </div>
            </div>
            <p class="text-center text-muted small mt-2">
                This may take 30-60 seconds. Please wait...
            </p>
        </div>

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
        uploadedImages: [],
        standardSize: '',
        width: '',
        height: '',
        unit: 'mm',
        templateCount: 1,
        loading: false,
        error: null,
        generatedTemplates: [],
        projectName: '',
        templateType: '',
        keywords: '',
        fontFamily: '',
        colorTheme: '',
        backgroundColor: '',
        previewedPrompts: [],

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
            const files = Array.from(event.target.files || []);
            if (files.length > 0) {
                files.forEach(file => {
                    if (file.type.startsWith('image/')) {
                        const previewUrl = URL.createObjectURL(file);
                        this.uploadedImages.push({
                            url: '',
                            previewUrl,
                            path: '',
                            name: file.name,
                            file: file
                        });
                    }
                });
            }
        },

        handleFileDrop(event) {
            const files = Array.from(event.dataTransfer.files || []);
            files.forEach(file => {
                if (file.type.startsWith('image/')) {
                    const previewUrl = URL.createObjectURL(file);
                    this.uploadedImages.push({
                        url: '',
                        previewUrl,
                        path: '',
                        name: file.name,
                        file: file
                    });
                }
            });
        },

        removeImage(index) {
            if (this.uploadedImages[index].previewUrl) {
                URL.revokeObjectURL(this.uploadedImages[index].previewUrl);
            }
            this.uploadedImages.splice(index, 1);
        },

        canPreviewPrompts() {
            return (this.selectedCategoryId || this.categoryName) && 
                   (this.templateType || this.keywords);
        },

        async previewPrompts() {
            if (!this.canPreviewPrompts()) {
                this.error = 'Please fill in at least category and one design preference (template type or keywords)';
                return;
            }

            this.loading = true;
            this.error = null;
            this.previewedPrompts = [];

            try {
                const payload = {
                    category_id: this.selectedCategoryId || null,
                    category_name: this.categoryName || null,
                    template_type: this.templateType || null,
                    keywords: this.keywords || null,
                    font_family: this.fontFamily || null,
                    color_theme: this.colorTheme || null,
                    background_color: this.backgroundColor || null,
                    number_of_templates: this.templateCount,
                };

                const response = await fetch('/api/generate/preview-prompts', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();
                if (response.ok && data.prompts) {
                    this.previewedPrompts = data.prompts;
                } else {
                    this.error = data.message || data.error || 'Failed to preview prompts';
                }
            } catch (e) {
                this.error = 'Failed to preview prompts: ' + e.message;
            } finally {
                this.loading = false;
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

            if (this.uploadedImages.length > 0) {
                this.uploadedImages.forEach((img, index) => {
                    if (img.file) {
                        formData.append('images[]', img.file);
                    }
                });
            } else if (this.uploadedImage && this.uploadedImage.file) {
                formData.append('image', this.uploadedImage.file);
            }

            if (this.standardSize) {
                formData.append('standard_size', this.standardSize);
            } else if (this.width && this.height) {
                formData.append('width', this.width);
                formData.append('height', this.height);
                formData.append('unit', this.unit);
            }

            formData.append('template_count', this.templateCount);

            if (this.projectName) {
                formData.append('project_name', this.projectName);
            }
            if (this.templateType) {
                formData.append('template_type', this.templateType);
            }
            if (this.keywords) {
                formData.append('keywords', this.keywords);
            }
            if (this.fontFamily) {
                formData.append('font_family', this.fontFamily);
            }
            if (this.colorTheme) {
                formData.append('color_theme', this.colorTheme);
            }
            if (this.backgroundColor) {
                formData.append('background_color', this.backgroundColor);
            }

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
                        const templateCount = Array.isArray(data.templates) ? data.templates.length : 0;
                        let redirectUrl = `/templates?success=1&count=${templateCount}`;
                        
                        if (data.category && data.category.id) {
                            redirectUrl += `&category_id=${data.category.id}`;
                        }
                        
                        window.location.href = redirectUrl;
                        return;
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

