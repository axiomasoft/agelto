<?php

declare(strict_types=1);

namespace Platform\Models\DocumentAnalysis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Dto\PromtRule\Group;

final class DocumentAnalysisResponse extends Model
{
    protected $table = 'plt_document_analysis_responses';

    protected $fillable = [
        'document_analysis_id',
        'parent_id',
        'file_cache_id',
        'success',
        'message',
        'data',
        'promt',
        'step',
        'state',
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
            'data' => 'array',
            'state' => 'array',
            'step' => Group::class,
        ];
    }
}
