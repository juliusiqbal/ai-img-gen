# AI Image Generator - Laravel Application

A Laravel-based application for generating SVG templates using AI image generation APIs.

## Features

-   Dynamic category management (create or select categories)
-   Upload sample images for template generation
-   AI-powered image generation using OpenAI GPT Image 1
-   Printing dimension calculations (standard sizes or custom)
-   SVG conversion and export
-   Download individual or batch templates as SVG files

## Requirements

-   PHP 8.2+
-   MySQL 5.7+ or MariaDB 10.3+
-   Composer
-   OpenAI API Key (for GPT Image 1 image generation)
-   GD extension (for image processing)

## Installation

1. Clone the repository or ensure you're in the project directory

2. Install dependencies:

```bash
composer install
```

3. Copy the `.env.example` to `.env` and configure:

```bash
cp .env.example .env
php artisan key:generate
```

4. Update `.env` file with your database credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=image_generator
DB_USERNAME=root
DB_PASSWORD=

OPENAI_API_KEY=your_openai_api_key_here
```

5. Create the database:

```bash
# Create database manually in MySQL:
# CREATE DATABASE image_generator;
```

6. Run migrations:

```bash
php artisan migrate
```

7. Create storage symlink:

```bash
php artisan storage:link
```

8. Create necessary directories:

```bash
mkdir -p storage/app/temp
mkdir -p storage/app/public/uploads
mkdir -p storage/app/public/svgs
mkdir -p storage/app/public/generated
```

## Usage

1. Start the development server:

```bash
php artisan serve
```

2. Visit `http://localhost:8000` in your browser

3. Navigate to the Generate page to create templates:
    - Select or create a category
    - Optionally upload a sample image
    - Specify printing dimensions (optional)
    - Click "Generate Templates"
    - Download generated SVG templates

## API Endpoints

### Categories

-   `GET /api/categories` - List all categories
-   `POST /api/categories` - Create a category
-   `GET /api/categories/{id}` - Get category details
-   `GET /api/categories/{id}/templates` - Get templates in category

### Generation

-   `POST /api/generate` - Generate templates
    -   Parameters:
        -   `category_id` (optional) - Existing category ID
        -   `category_name` (optional) - New category name
        -   `image` (optional) - Sample image file
        -   `width`, `height`, `unit` (optional) - Custom dimensions
        -   `standard_size` (optional) - Standard size (A4, A3, etc.)
        -   `template_count` (optional) - Number of templates (1-10, default: 1)

### Downloads

-   `GET /api/templates/{id}/download` - Download single template
-   `POST /api/templates/download-batch` - Download multiple templates (requires `template_ids[]`)
-   `GET /api/categories/{categoryId}/download` - Download all templates in category

## Configuration

### OpenAI API

The application uses OpenAI GPT Image 1 API for image generation. Make sure you have:

-   A valid OpenAI API key
-   Sufficient API credits

### Storage

Generated files are stored in:

-   `storage/app/public/uploads/` - Uploaded sample images
-   `storage/app/public/svgs/` - Generated SVG files
-   `storage/app/public/generated/` - Generated AI images

## Troubleshooting

1. **Storage link not working**: Run `php artisan storage:link`
2. **Database connection error**: Check your `.env` database credentials
3. **OpenAI API errors**: Verify your API key and check your account credits
4. **Image upload fails**: Check file size limits and PHP upload settings
5. **SVG conversion fails**: The app will fallback to embedding raster images in SVG if Potrace is not available

## Notes

-   SVG conversion attempts to use Potrace for vectorization, but falls back to embedding raster images if Potrace is not installed
-   The application requires an active internet connection for AI image generation
-   Generated images are downloaded from OpenAI and stored locally before conversion

## License

This project is open-sourced software.
