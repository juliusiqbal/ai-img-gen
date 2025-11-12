<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    protected $fillable = [
        'category_id',
        'original_image_path',
        'svg_path',
        'dimensions',
        'printing_dimensions',
        'prompt_used',
        'project_name',
        'generation_prompt',
        'design_preferences',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'printing_dimensions' => 'array',
        'design_preferences' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
