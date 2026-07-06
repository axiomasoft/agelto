<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\LlmPurpose;
use Database\Factories\Procurement\Ai\AiRouteFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Матрица роутинга «тип документа × назначение → провайдер/модель» (без spatie-settings —
 * настройка многострочная, см. docs/03_Dev/database.md §6c).
 */
#[UseFactory(AiRouteFactory::class)]
final class AiRoute extends Model
{
    use HasFactory;

    protected $table = 'prc_ai_routes';

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
            'purpose' => LlmPurpose::class,
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
