<?php

declare(strict_types=1);

namespace App\Repositories\Llm;

use App\Models\Llm\Prompt;

/**
 * Запись `llm_prompts` — единственная точка персистентности для канона промтов (D5/D17,
 * `root/service-layer-map.md` §7); введение отнесено к P3 (сервисный слой) замороженным
 * контрактом P2.1 §7.
 */
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
