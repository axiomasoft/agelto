<?php

declare(strict_types=1);

namespace App\Models\Llm;

use Database\Factories\Llm\LlmRouteFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Матрица роутинга «тип документа × назначение → провайдер/модель» (без spatie-settings —
 * настройка многострочная, см. docs/03_Dev/database.md §6c).
 *
 * `document_type`/`purpose` — СТРОКИ (без каста): типизированное чтение носителя `purpose`,
 * когда понадобится, идёт через `DocumentAnalyzerRegistry::purposeFor($route->document_type,
 * $route->purpose)` — тип берётся из ТОЙ ЖЕ строки, а не угадывается (P2.5, D101).
 */
#[UseFactory(LlmRouteFactory::class)]
final class LlmRoute extends Model
{
    use HasFactory;

    protected $table = 'llm_routes';

    protected $fillable = [
        'document_type',
        'purpose',
        'provider_id',
        'model',
        'options',
        'priority',
        'is_active',
        'shadow',
    ];

    protected function casts(): array
    {
        return [
            'options' => AsCollection::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
            'shadow' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(related: Provider::class);
    }
}
