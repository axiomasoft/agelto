<?php

declare(strict_types=1);

namespace App\Models\Procurement\Crawler;

use App\Models\Procurement\Platform\Registry;
use Database\Factories\Procurement\Crawler\IgnoreRuleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Правило автоматического пропуска закупок (данные, не код) — попадание помечает
 * закупку как `ignored` и порождает событие.
 */
#[UseFactory(IgnoreRuleFactory::class)]
final class IgnoreRule extends Model
{
    use HasFactory;

    protected $table = 'prc_ignore_rules';

    protected $fillable = [
        'registry_id',
        'field',
        'operator',
        'value',
        'reason_category',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function registry(): BelongsTo
    {
        return $this->belongsTo(related: Registry::class);
    }
}
