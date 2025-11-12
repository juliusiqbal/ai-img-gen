<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Models\Category;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class DownloadController extends Controller
{
    /**
     * Download single template as SVG
     */
    public function downloadTemplate(string $id): BinaryFileResponse
    {
        $template = Template::findOrFail($id);

        if (!$template->svg_path) {
            abort(404, 'SVG file not found');
        }

        $path = storage_path('app/public/' . $template->svg_path);

        if (!file_exists($path)) {
            abort(404, 'File not found');
        }

        return response()->download($path, basename($template->svg_path), [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    /**
     * Download multiple templates as ZIP
     */
    public function downloadBatch(Request $request): BinaryFileResponse
    {
        $request->validate([
            'template_ids' => 'required|array',
            'template_ids.*' => 'exists:templates,id',
        ]);

        $templates = Template::whereIn('id', $request->template_ids)->get();

        if ($templates->isEmpty()) {
            abort(400, 'No templates found');
        }

        $zipPath = storage_path('app/temp/download_' . time() . '.zip');
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            abort(500, 'Cannot create ZIP file');
        }

        foreach ($templates as $template) {
            if ($template->svg_path && file_exists(storage_path('app/public/' . $template->svg_path))) {
                $zip->addFile(
                    storage_path('app/public/' . $template->svg_path),
                    'template_' . $template->id . '_' . basename($template->svg_path)
                );
            }
        }

        $zip->close();

        return response()->download($zipPath, 'templates.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Download all templates in a category
     */
    public function downloadCategory(string $categoryId): BinaryFileResponse
    {
        $category = Category::findOrFail($categoryId);
        $templates = $category->templates;

        if ($templates->isEmpty()) {
            abort(400, 'No templates found in this category');
        }

        $zipPath = storage_path('app/temp/category_' . $category->id . '_' . time() . '.zip');
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            abort(500, 'Cannot create ZIP file');
        }

        foreach ($templates as $template) {
            if ($template->svg_path && file_exists(storage_path('app/public/' . $template->svg_path))) {
                $zip->addFile(
                    storage_path('app/public/' . $template->svg_path),
                    'template_' . $template->id . '_' . basename($template->svg_path)
                );
            }
        }

        $zip->close();

        return response()->download($zipPath, $category->name . '_templates.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Download all templates (from selected IDs or all)
     */
    public function downloadAll(Request $request): BinaryFileResponse
    {
        $request->validate([
            'template_ids' => 'nullable|array',
            'template_ids.*' => 'exists:templates,id',
            'project_name' => 'nullable|string',
        ]);

        // Get templates - either by IDs or by project name
        if ($request->has('template_ids') && !empty($request->template_ids)) {
            $templates = Template::whereIn('id', $request->template_ids)->get();
        } elseif ($request->has('project_name') && $request->project_name) {
            $templates = Template::where('project_name', $request->project_name)->get();
        } else {
            // Download all templates
            $templates = Template::all();
        }

        if ($templates->isEmpty()) {
            abort(400, 'No templates found');
        }

        // Ensure temp directory exists
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $zipPath = storage_path('app/temp/all_templates_' . time() . '.zip');
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            abort(500, 'Cannot create ZIP file');
        }

        foreach ($templates as $template) {
            if ($template->svg_path && file_exists(storage_path('app/public/' . $template->svg_path))) {
                $filename = $template->project_name
                    ? $template->project_name . '_' . $template->id . '_' . basename($template->svg_path)
                    : 'template_' . $template->id . '_' . basename($template->svg_path);
                $zip->addFile(
                    storage_path('app/public/' . $template->svg_path),
                    $filename
                );
            }
        }

        $zip->close();

        $zipName = $request->project_name
            ? $request->project_name . '_templates.zip'
            : 'all_templates.zip';

        return response()->download($zipPath, $zipName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
