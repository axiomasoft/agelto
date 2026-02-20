<?php

declare(strict_types=1);

namespace Platform\Models\DocumentAnalysis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Dto\PromtRule\Step;

final class DocumentAnalysisResponse extends Model
{
    protected $table = 'plt_document_analysis_responses';

    protected $fillable = [
        'document_analysis_id',
        'parent_id',
        'promt',
        'step',
        'cache_file_id',
        'rules_hash',
        'success',
        'message',
        'data',
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
            'success' => 'boolean',
            'step' => Step::class,
        ];
    }
}
