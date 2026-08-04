<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Models\Procurement\Platform\Registry;
use Database\Factories\Procurement\Crawler\CrawlerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Профиль обхода площадки: режим, метод забора данных и фильтры.
 *
 * @property int $id
 * @property int $registry_id
 * @property string $name
 * @property CrawlerMode $mode
 * @property SourceMethod $source_method
 * @property SourceMethod|null $fallback_method
 * @property Collection<array-key, mixed>|null $filter
 * @property string|null $schedule
 * @property Collection<array-key, mixed>|null $options
 * @property bool $is_active
 * @property bool $use_proxy
 * @property string|null $proxy_country
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(CrawlerFactory::class)]
final class Crawler extends Model
{
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
        'use_proxy',
        'proxy_country',
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
            'use_proxy' => 'boolean',
        ];
    }

    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }
}
