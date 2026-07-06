<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use Database\Factories\Procurement\Ai\PromptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Версионированный промпт для шага AI-анализа.
 *
 * @use HasFactory<PromptFactory>
 */
final class Prompt extends Model
{
    /** @use HasFactory<PromptFactory> */
    use HasFactory;

    protected $table = 'prc_ai_prompts';

    protected $fillable = [
        'key',
        'step',
        'content',
        'version',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
