<?php

declare(strict_types=1);

namespace Platform\Models\Promt;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Platform\Dto\PromtRule\Step;

final class PromptRule extends Model
{
    protected $table = 'plt_prompt_rules';

    /**
     * Атрибуты, для которых разрешено массовое заполнение.
     */
    protected $fillable = [
        'key',
        'step',
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
        'step' => Step::class,
    ];

    /**
     * Scope для получения только активных правил.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
