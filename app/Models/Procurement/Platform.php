<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\PlatformFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Площадка-источник закупок (ЕИС и т.п.).
 *
 * @use HasFactory<PlatformFactory>
 */
final class Platform extends Model
{
    /** @use HasFactory<PlatformFactory> */
    use HasFactory;

    protected $table = 'prc_platforms';

    protected $fillable = [
        'uuid',
        'code',
        'name',
        'base_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $platform): void {
            $platform->uuid ??= (string) Str::uuid7();
        });
    }
}
