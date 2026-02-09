<?php

declare(strict_types=1);

namespace Platform\Models\Crawling;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Analytics extends Model
{
    use HasFactory;

    protected $table = 'plt_crawler_analytics';

    protected $fillable = [
        'date',
        'crawler_id',
        's1_found',
        's1_errors',
        's2_docs_indexed',
        's2_errors',
        's3_ai_processed',
        's3_smeta_found',
        's3_errors',
        's4_parsed_items',
        's4_errors',
    ];

    public function crawler(): BelongsTo
    {
        return $this->belongsTo(Crawler::class);
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
