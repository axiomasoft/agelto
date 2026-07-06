<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use Database\Factories\Procurement\Crawler\DailyStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Посуточная аналитика по стадиям одного краулера — готовый срез для дашборда.
 *
 * @use HasFactory<DailyStatFactory>
 */
final class DailyStat extends Model
{
    /** @use HasFactory<DailyStatFactory> */
    use HasFactory;

    protected $table = 'prc_daily_stats';

    protected $fillable = [
        'date',
        'crawler_id',
        'listing_found',
        'listing_errors',
        'detail_fetched',
        'detail_errors',
        'docs_indexed',
        'docs_errors',
        'screened',
        'estimates_found',
        'screen_errors',
        'extracted_items',
        'extract_errors',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'listing_found' => 'integer',
            'listing_errors' => 'integer',
            'detail_fetched' => 'integer',
            'detail_errors' => 'integer',
            'docs_indexed' => 'integer',
            'docs_errors' => 'integer',
            'screened' => 'integer',
            'estimates_found' => 'integer',
            'screen_errors' => 'integer',
            'extracted_items' => 'integer',
            'extract_errors' => 'integer',
        ];
    }

    public function crawler(): BelongsTo
    {
        return $this->belongsTo(related: Crawler::class);
    }
}
