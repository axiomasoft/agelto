<?php

declare(strict_types=1);

namespace App\Models\Llm;

use Database\Factories\Llm\PromptFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Версионированный промпт для шага AI-анализа.
 *
 * @property string $document_type
 * @property string $key
 * @property string $step
 * @property string $content
 * @property int $version
 * @property int $priority
 * @property bool $is_active
 * @property bool $is_protected
 */
#[UseFactory(PromptFactory::class)]
final class Prompt extends Model
{
    use HasFactory;

    protected $table = 'llm_prompts';

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
