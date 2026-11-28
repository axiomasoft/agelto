<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use Database\Factories\Procurement\ProtocolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Протокол процедуры (рассмотрение заявок, аукцион, оценка, итоги и т.д.).
 * `type` — текстовое поле: закрытый codelist для 44-ФЗ, open-ended для 223-ФЗ
 * (см. App\Enums\Procurement\ProtocolType).
 *
 * @property string $uuid
 */
#[UseFactory(ProtocolFactory::class)]
#[Fillable([
    'uuid',
    'procurement_id',
    'type',
    'stage',
    'source_number',
    'signed_at',
    'published_at',
    'document_version_id',
    'payload',
])]
#[Table(name: 'prc_protocols')]
final class Protocol extends Model
{
    /** @use HasFactory<ProtocolFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'published_at' => 'datetime',
            'payload' => AsCollection::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $protocol): void {
            $protocol->uuid ??= (string) Str::uuid7();
        });
    }
}
