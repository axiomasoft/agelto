<?php

declare(strict_types=1);

namespace App\Repositories\Llm;

use App\Models\Llm\Prompt;

/** Единственная точка записи runtime-копии канона промтов в `llm_prompts`. */
final readonly class PromptStoreRepository
{
    /**
     * @param  array{document_type: string, key: string, step: string, content: string, version: int, priority: int, is_active: bool}  $record
     */
    public function storeOrUpdate(array $record): Prompt
    {
        return Prompt::query()->updateOrCreate(
            ['document_type' => $record['document_type'], 'key' => $record['key'], 'version' => $record['version']],
            [
                'step' => $record['step'],
                'content' => $record['content'],
                'priority' => $record['priority'],
                'is_active' => $record['is_active'],
                'is_protected' => true,
            ],
        );
    }

    public function deactivate(Prompt $prompt): void
    {
        $prompt->update(['is_active' => false]);
    }
}
