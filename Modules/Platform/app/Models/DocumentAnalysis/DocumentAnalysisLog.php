<?php

declare(strict_types=1);

namespace Platform\Models\DocumentAnalysis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Enums\AnalysisStatus;

final class DocumentAnalysisLog extends Model
{
    protected $table = 'plt_document_analysis_logs';
    protected $fillable = [
        'document_analysis_id',
        'status',
        'message',
        'content',
        'trace',
    ];

    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'content' => 'array',
        ];
    }
}
