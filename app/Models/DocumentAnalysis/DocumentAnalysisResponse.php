<?php

declare(strict_types=1);

namespace App\Models\DocumentAnalysis;

use App\Dto\Prompt\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DocumentAnalysisResponse extends Model
{
    protected $table = 'plt_document_analysis_responses';

    protected $fillable = [
        'document_analysis_id',
        'parent_id',
        'prompt',
        'step',
        'cache_file_id',
        'rules_hash',
        'success',
        'message',
        'data',
        'state',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
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
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
        ];
    }
}
