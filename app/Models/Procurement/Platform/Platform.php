<?php

declare(strict_types=1);

namespace App\Models\Procurement\Platform;

use App\Dto\Procurement\Crawler\PlatformThrottleSettings;
use Database\Factories\Procurement\Platform\PlatformFactory;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Площадка-источник закупок (ЕИС и т.п.) либо служебный контур обучения (`code=training`,
 * `is_public=false`) — оба различаются РОВНО этой строкой, без второй оси на документе.
 *
 * @property PlatformThrottleSettings|null $throttle
 * @property string $uuid
 */
#[UseFactory(PlatformFactory::class)]
#[Fillable([
    'uuid',
    'code',
    'name',
    'base_url',
    'throttle',
    'is_active',
    'is_public',
])]
#[Table(name: 'prc_platforms')]
final class Platform extends Model
{
    /** @use HasFactory<PlatformFactory> */
    use HasFactory;

    /**
     * Код служебной площадки обучающего контура — единственный
     * носитель литерала, чтобы GoldImportCommand/DatasetImportCommand не дублировали строку.
     */
    public const string TRAINING_CODE = 'training';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Служебная площадка обучения создаётся сидером и определяет `platform_id` документов
     * обучающего контура.
     */
    public static function training(): self
    {
        return self::query()->where('code', self::TRAINING_CODE)->firstOrFail();
    }

    protected static function booted(): void
    {
        self::saving(static function (self $platform): void {
            $platform->resolveThrottle(raw: $platform->rawThrottle());
        });

        self::creating(function (self $platform): void {
            $platform->uuid ??= (string) Str::uuid7();
        });
    }

    /** @return Attribute<?PlatformThrottleSettings, PlatformThrottleSettings|array<string, mixed>|Arrayable<string, mixed>|null> */
    protected function throttle(): Attribute
    {
        return Attribute::make(
            get: fn (): ?PlatformThrottleSettings => $this->resolveThrottle(raw: $this->rawThrottle()),
            set: static fn (PlatformThrottleSettings|array|Arrayable|null $value): array => [
                'throttle' => $value === null ? null : json_encode(
                    $value instanceof Arrayable ? $value->toArray() : $value,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            ],
        );
    }

    /** @return array<string, mixed>|null */
    private function rawThrottle(): ?array
    {
        $raw = $this->attributes['throttle'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = is_string($raw) ? json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR) : $raw;

        if (! is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $key => $_) {
            if (! is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed>|null $raw */
    private function resolveThrottle(?array $raw): ?PlatformThrottleSettings
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        return PlatformThrottleSettings::validateAndCreate($raw);
    }
}
