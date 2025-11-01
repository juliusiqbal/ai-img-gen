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
    ];

    protected $casts = [
        'dimensions' => 'array',
        'printing_dimensions' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
