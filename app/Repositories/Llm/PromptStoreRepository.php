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
        $prompt = Prompt::query()->lockForUpdate()->firstOrNew([
            'document_type' => $record['document_type'],
            'key' => $record['key'],
            'version' => $record['version'],
        ]);

        $prompt->fill([
            'step' => $record['step'],
            'content' => $record['content'],
            'priority' => $record['priority'],
        ]);
        if ($prompt->exists && $prompt->is_protected && $prompt->isDirty(['step', 'content', 'priority'])) {
            throw new \RuntimeException("Защищённый промт {$prompt->document_type}/{$prompt->key} v{$prompt->version} изменён. Увеличьте version в каноне.");
        }

        $prompt->fill([
            'is_active' => $record['is_active'],
        ])->forceFill([
            'is_protected' => true,
        ])->save();

        return $prompt;
    }

    public function deactivate(Prompt $prompt): void
    {
        $prompt->update(['is_active' => false]);
    }
}
