<?php

declare(strict_types=1);

namespace Platform\Models\DocumentAnalysis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Details extends Model
{
    protected $table = 'plt_document_analysis_details';

    protected $fillable = [
        'document_analysis_id',
        'parent_id',
        'page_name',
        'page_number',
        'page_meta',
        'content',
        'full_response',
        'status',
        'completed_at',
        'attempts',
        'last_error',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    protected function casts(): array
    {
        return [
            'page_meta' => 'array',
            'content' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
