<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Dto\Procurement\Crawler\Contracts\RequiresScope;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\CrawlerFilterFactory;
use Database\Factories\Procurement\Crawler\CrawlerFactory;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Профиль обхода площадки: режим, метод забора данных и фильтры.
 *
 * @property int $id
 * @property int $registry_id
 * @property string $name
 * @property CrawlerMode $mode
 * @property SourceMethod $source_method
 * @property SourceMethod|null $fallback_method
 * @property Data|null $filter
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
            'options' => AsCollection::class,
            'is_active' => 'boolean',
            'use_proxy' => 'boolean',
        ];
    }

    /**
     * Гейт записи: неизвестный ключ фильтра обязан отвергаться в момент сохранения профиля, а не
     * на боевом прогоне — иначе опечатка оператора доживает до запроса к площадке и молча
     * расширяет выборку (P4.3 Why). Читающая сторона защищена аксессором `filter` ниже.
     */
    protected static function booted(): void
    {
        self::saving(static function (self $crawler): void {
            $filter = $crawler->resolveFilter(raw: $crawler->rawFilter());

            $crawler->assertScope(filter: $filter);
        });
    }

    /**
     * Второй гейт записи (D18): фильтр, объявивший себя `RequiresScope`, обязан нести непустой
     * предметный скоуп — иначе профиль не сохраняется ни с `filter = null`, ни с пустым скоупом.
     * Проверка идёт по КОНТРАКТУ, а не по коду реестра (`if ($registry->code === 'fz44')`
     * запрещён каноном ветки, `CrawlerForm` docblock).
     *
     * Текст отказа называет реестр и конкретное требование: единственный сегодняшний носитель
     * контракта — `EisSearchFilter`, поэтому «узел ОКПД2» стоит прямо здесь. Появится второй
     * реализующий фильтр — формулировка переезжает в контракт (тот же приём отложенного
     * обобщения, что у `HasPriceRange`).
     */
    private function assertScope(?Data $filter): void
    {
        $registry = $this->registry;

        if (! $registry instanceof Registry) {
            return;
        }

        try {
            $class = app(CrawlerFilterFactory::class)->for(registry: $registry);
        } catch (RuntimeException) {
            // Реестр без записи в `config('crawler.filters')` — скоуп ему неизвестен, и это не
            // повод отвергать профиль: класс фильтра появится вместе с веткой площадки.
            return;
        }

        if (! is_subclass_of($class, RequiresScope::class)) {
            return;
        }

        if ($filter instanceof RequiresScope && $filter->hasScope()) {
            return;
        }

        throw ValidationException::withMessages([
            'filter' => sprintf(
                'Профиль реестра «%s» обязан нести предметный скоуп: выберите хотя бы один узел '
                .'ОКПД2. Профиль без скоупа расширяет выборку на весь реестр.',
                (string) $registry->getAttribute('code'),
            ),
        ]);
    }

    /**
     * `filter` хранится прежним `jsonb` (D34), а наружу отдаётся типизированным объектом:
     * класс резолвится по коду реестра через `config('crawler.filters')`, поэтому у другой
     * площадки/реестра — свой `Data`-класс, а не общий мешок ключей.
     */
    protected function filter(): Attribute
    {
        return Attribute::make(
            get: fn (): ?Data => $this->resolveFilter(raw: $this->rawFilter()),
            set: static fn (Data|array|Arrayable|null $value): array => [
                'filter' => $value === null ? null : json_encode(
                    $value instanceof Arrayable ? $value->toArray() : $value,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            ],
        );
    }

    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawFilter(): ?array
    {
        $raw = $this->attributes['filter'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = is_string($raw) ? json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR) : $raw;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function resolveFilter(?array $raw): ?Data
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        $registry = $this->registry;

        if (! $registry instanceof Registry) {
            throw new RuntimeException(
                'Фильтр профиля обхода не типизировать без реестра: связь `registry` не резолвится '
                .'(модель загружена без `registry_id`?). Класс фильтра выбирается по коду реестра.'
            );
        }

        $class = app(CrawlerFilterFactory::class)->for(registry: $registry);

        return $class::validateAndCreate($raw);
    }
}
