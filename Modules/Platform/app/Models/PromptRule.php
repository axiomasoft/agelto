<?php

declare(strict_types=1);

namespace Platform\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class PromptRule extends Model
{
    protected $table = 'platform_promt_rules';

    /**
     * Атрибуты, для которых разрешено массовое заполнение.
     */
    protected $fillable = [
        'key',
        'group',
        'content',
        'version',
        'priority',
        'is_active',
    ];

    /**
     * Типизация атрибутов.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'version' => 'integer',
    ];

    /**
     * Собрать финальный текст промпта для конкретной группы.
     *
     * * @param string $group Название группы (primary_analysis, detailed_parsing и т.д.)
     */
    public static function buildPrompt(string $group): string
    {
        return self::query()
            ->where('group', $group)
            ->active()
            ->orderBy('priority', 'asc')
            ->pluck('content')
            ->implode("\n\n");
    }

    /**
     * Получить все правила, сгруппированные по ключу 'group'.
     * Полезно для отладки или массовой сборки промпта.
     */
    public static function getAllGrouped(): Collection
    {
        return self::query()
            ->active()
            ->orderBy('priority')
            ->get()
            ->groupBy('group')
            ->map(function ($items) {
                return $items->pluck('content')->implode("\n\n");
            });
    }

    /**
     * Scope для получения только активных правил.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
