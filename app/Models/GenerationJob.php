<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationJob extends Model
{
    protected $fillable = [
        'category_id',
        'status',
        'request_data',
        'error_message',
    ];

    protected $casts = [
        'request_data' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
