<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use Database\Factories\Procurement\Ai\PromptFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Версионированный промпт для шага AI-анализа.
 *
 * @property string $document_type
 */
#[UseFactory(PromptFactory::class)]
final class Prompt extends Model
{
    use HasFactory;

    protected $table = 'prc_ai_prompts';

    protected $fillable = [
        'document_type',
        'key',
        'step',
        'content',
        'version',
        'priority',
        'is_active',
        'is_protected',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'is_protected' => 'boolean',
        ];
    }
}
