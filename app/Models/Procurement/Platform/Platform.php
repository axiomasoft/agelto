<?php

declare(strict_types=1);

namespace App\Models\Procurement\Platform;

use Database\Factories\Procurement\Platform\PlatformFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Площадка-источник закупок (ЕИС и т.п.) либо служебный контур обучения (`code=training`,
 * `is_public=false`, D91) — оба различаются РОВНО этой строкой, без второй оси на документе.
 */
#[UseFactory(PlatformFactory::class)]
final class Platform extends Model
{
    use HasFactory;

    /**
     * Код служебной площадки обучающего контура (§1.3 root/document-contour.md) — единственный
     * носитель литерала, чтобы GoldImportCommand/DatasetImportCommand не дублировали строку.
     */
    public const string TRAINING_CODE = 'training';

    protected $table = 'prc_platforms';

    protected $fillable = [
        'uuid',
        'code',
        'name',
        'base_url',
        'is_active',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Служебная площадка «Обучалка» (D91) — заводится сидером, единственный писатель
     * `platform_id` документов обучающего контура (§1.4).
     */
    public static function training(): self
    {
        return self::query()->where('code', self::TRAINING_CODE)->firstOrFail();
    }

    protected static function booted(): void
    {
        self::creating(function (self $platform): void {
            $platform->uuid ??= (string) Str::uuid7();
        });
    }
}
