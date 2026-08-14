<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Dto\Procurement\Crawler\Contracts\RequiresScope;
use App\Dto\Procurement\Crawler\CrawlerRuntimeOptions;
use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\CrawlerFilterFactory;
use Database\Factories\Procurement\Crawler\CrawlerFactory;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Профиль обхода площадки: режим, метод забора данных и фильтры.
 *
 * @property int $id
 * @property int $registry_id
 * @property string $name
 * @property CrawlerExecutionLane $execution_lane
 * @property CrawlerMode $mode
 * @property RunStopPolicy $stop_policy
 * @property SourceMethod $source_method
 * @property SourceMethod|null $fallback_method
 * @property Data|null $filter
 * @property string|null $schedule
 * @property CrawlerRuntimeOptions|null $options
 * @property bool $is_active
 * @property CrawlerProxyMode $proxy_mode
 * @property string|null $proxy_country
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(CrawlerFactory::class)]
#[Fillable([
    'registry_id',
    'name',
    'execution_lane',
    'mode',
    'stop_policy',
    'source_method',
    'fallback_method',
    'filter',
    'schedule',
    'options',
    'is_active',
    'proxy_mode',
    'proxy_country',
])]
#[Table(name: 'prc_crawlers')]
final class Crawler extends Model
{
    /** @use HasFactory<CrawlerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'execution_lane' => CrawlerExecutionLane::class,
            'mode' => CrawlerMode::class,
            'stop_policy' => RunStopPolicy::class,
            'source_method' => SourceMethod::class,
            'fallback_method' => SourceMethod::class,
            'is_active' => 'boolean',
            'proxy_mode' => CrawlerProxyMode::class,
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
            $crawler->resolveRuntimeOptions(raw: $crawler->rawOptions());

            $crawler->assertScope(filter: $filter);
            $crawler->assertStopPolicy();
            $crawler->assertProxyBinding();
        });
    }

    /**
     * Третий гейт записи (P3.3): `RunStopPolicy::OnlyFresh` осмыслен только у fresh-прогона —
     * archive-профиль с этой политикой не отвергается на прогоне (стоп-условие живёт в
     * `FreshRunProcessor`, до которого archive никогда не доходит), а молча остаётся мёртвой
     * настройкой, если не запретить сохранение здесь.
     */
    private function assertStopPolicy(): void
    {
        if ($this->stop_policy !== RunStopPolicy::OnlyFresh) {
            return;
        }

        if ($this->mode === CrawlerMode::Fresh) {
            return;
        }

        throw ValidationException::withMessages([
            'stop_policy' => sprintf(
                'Режим работы «только актуальные данные» доступен только профилю с mode=fresh '
                .'(текущий mode=%s): политика останова читает свежий обход, архивный её не применяет.',
                $this->mode->value,
            ),
        ]);
    }

    /**
     * Прокси включается только вместе с явным набором: иначе пустая настройка превратится в
     * неограниченный выбор любого доступного узла и обойдёт политику профиля (D38).
     */
    private function assertProxyBinding(): void
    {
        if ($this->proxy_mode !== CrawlerProxyMode::Profile) {
            return;
        }

        if ($this->exists && $this->proxies()->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'proxies' => 'Для включения прокси привяжите к профилю хотя бы один узел.',
        ]);
    }

    /**
     * Второй гейт записи (D18): фильтр, объявивший себя `RequiresScope`, обязан нести непустой
     * предметный скоуп — иначе профиль не сохраняется ни с `filter = null`, ни с пустым скоупом.
     * Проверка идёт по контракту capability адаптера, а не по конкретному коду реестра.
     */
    private function assertScope(?Data $filter): void
    {
        $registry = $this->registry;

        if (! $registry instanceof Registry) {
            return;
        }

        $filters = app(CrawlerFilterFactory::class);
        $class = $filters->for(registry: $registry);

        if (! is_subclass_of($class, RequiresScope::class)) {
            return;
        }

        if ($filter instanceof RequiresScope && $filter->hasScope()) {
            return;
        }

        throw ValidationException::withMessages([
            'filter' => sprintf(
                'Профиль реестра «%s» обязан нести предметный скоуп: выберите хотя бы один узел '
                .'%s',
                $this->registryCode($registry),
                $filters->scopeMessage(registry: $registry),
            ),
        ]);
    }

    /**
     * `filter` хранится прежним `jsonb` (D34), а наружу отдаётся типизированным объектом:
     * класс резолвится из nested registry capability exact platform adapter, поэтому у другой
     * площадки/реестра — свой `Data`-класс, а не общий мешок ключей.
     */
    /** @return Attribute<?Data, Data|array<string, mixed>|Arrayable<string, mixed>|null> */
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

    /** @return Attribute<?CrawlerRuntimeOptions, CrawlerRuntimeOptions|array<string, mixed>|Arrayable<string, mixed>|null> */
    protected function options(): Attribute
    {
        return Attribute::make(
            get: fn (): ?CrawlerRuntimeOptions => $this->resolveRuntimeOptions(raw: $this->rawOptions()),
            set: static fn (CrawlerRuntimeOptions|array|Arrayable|null $value): array => [
                'options' => $value === null ? null : json_encode(
                    $value instanceof Arrayable ? $value->toArray() : $value,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            ],
        );
    }

    /** @return BelongsTo<Registry, $this> */
    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }

    /** @return BelongsToMany<Proxy, $this> */
    public function proxies(): BelongsToMany
    {
        return $this->belongsToMany(related: Proxy::class, table: 'prc_crawler_proxy');
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

        return $this->jsonObject($decoded);
    }

    /** @return array<string, mixed>|null */
    private function rawOptions(): ?array
    {
        $raw = $this->attributes['options'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = is_string($raw) ? json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR) : $raw;

        return $this->jsonObject($decoded);
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

    /** @param array<string, mixed>|null $raw */
    private function resolveRuntimeOptions(?array $raw): ?CrawlerRuntimeOptions
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        return CrawlerRuntimeOptions::validateAndCreate($raw);
    }

    private function registryCode(Registry $registry): string
    {
        $code = $registry->getAttribute('code');

        if (! is_string($code) || $code === '') {
            throw new RuntimeException('Реестр профиля обхода не содержит code.');
        }

        return $code;
    }

    /** @return array<string, mixed>|null */
    private function jsonObject(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
