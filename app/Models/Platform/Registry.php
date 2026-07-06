<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Database\Factories\Platform\RegistryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Реестр внутри площадки (у ЕИС — fz44/fz223).
 *
 * @use HasFactory<RegistryFactory>
 */
final class Registry extends Model
{
    /** @use HasFactory<RegistryFactory> */
    use HasFactory;

    protected $table = 'prc_registries';

    protected $fillable = [
        'uuid',
        'platform_id',
        'code',
        'name',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $registry): void {
            $registry->uuid ??= (string) Str::uuid7();
        });
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(related: Platform::class);
    }
}
