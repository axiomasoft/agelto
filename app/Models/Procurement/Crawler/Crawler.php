<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\CrawlerMode;
use App\Enums\Procurement\SourceMethod;
use App\Models\Procurement\Platform\Registry;
use Database\Factories\Procurement\Crawler\CrawlerFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Профиль обхода площадки: режим, метод забора данных и фильтры.
 *
 * @use HasFactory<CrawlerFactory>
 */
final class Crawler extends Model
{
    /** @use HasFactory<CrawlerFactory> */
    use HasFactory;

    protected $table = 'prc_crawlers';

    protected $fillable = [
        'registry_id',
        'name',
        'mode',
        'source_method',
        'fallback_method',
        'filter',
        'schedule',
        'options',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'mode' => CrawlerMode::class,
            'source_method' => SourceMethod::class,
            'fallback_method' => SourceMethod::class,
            'filter' => AsCollection::class,
            'options' => AsCollection::class,
            'is_active' => 'boolean',
        ];
    }

    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }
}
