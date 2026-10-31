<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Dto\Procurement\Crawler\Contracts\RequiresScope;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Network\NetworkRouteProfile;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Профиль обхода площадки: режим, метод забора данных и фильтры.
 *
 * @property int $id
 * @property int $registry_id
 * @property int $profile_version
 * @property string $name
 * @property AcquisitionKind $listing_kind
 * @property Data|null $filter
 * @property string|null $schedule
 * @property bool $is_active
 * @property string|null $route_profile_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(CrawlerFactory::class)]
#[Fillable([
    'registry_id',
    'name',
    'listing_kind',
    'filter',
    'schedule',
    'is_active',
    'route_profile_id',
])]
#[Table(name: 'prc_crawlers')]
final class Crawler extends Model
{
    /** Every persisted edit gets a serialized immutable profile version. */
    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            if ($this->exists) {
                $current = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
                $this->setAttribute('profile_version', $current->profile_version + 1);
            } else {
                $this->setAttribute('profile_version', 1);
            }

            return parent::save($options);
        });
    }

    /** @use HasFactory<CrawlerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'profile_version' => 'integer',
            'listing_kind' => AcquisitionKind::class,
            'is_active' => 'boolean',
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

    /** @return BelongsTo<Registry, $this> */
    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }

    /** @return BelongsToMany<Proxy, $this> */
    /** @return BelongsTo<NetworkRouteProfile, $this> */
    public function routeProfile(): BelongsTo
    {
        return $this->belongsTo(NetworkRouteProfile::class, 'route_profile_id');
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
