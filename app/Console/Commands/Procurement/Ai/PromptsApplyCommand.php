<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Enums\Procurement\Ai\PromptSuggestionKind;
use App\Enums\Procurement\Ai\PromptSuggestionStatus;
use App\Repositories\Procurement\PromptSuggestionReadRepository;
use App\Services\Procurement\Tuning\PromptPatchApplier;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Применение УЖЕ одобренного предложения из очереди (P7.4, D8): работает ТОЛЬКО со
 * `status=approved` — {@see PromptPatchApplier} отказывает на любом другом статусе, ничего не
 * трогая (ни файл, ни БД). Команда САМА не пишет `llm_prompts` — файл коммитит и
 * `ai:prompts:sync` гоняет человек (D59).
 */
final class PromptsApplyCommand extends Command
{
    protected $signature = 'ai:prompts:apply
        {id : id предложения в очереди}
        {--dry-run : показать план применения без записи}';

    protected $description = 'Применить одобренное предложение из очереди prc_ai_prompt_suggestions';

    public function __construct(
        private readonly PromptSuggestionReadRepository $suggestions,
        private readonly PromptPatchApplier $applier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $dryRun = (bool) $this->option('dry-run');

        $suggestion = $this->suggestions->find($id);

        if ($suggestion === null) {
            $this->error("Предложение #{$id} не найдено.");

            return self::FAILURE;
        }

        if ($suggestion->status !== PromptSuggestionStatus::Approved) {
            $this->error("Предложение #{$id} не в статусе approved (текущий: {$suggestion->status->value}) — применение отказано.");

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line($suggestion->kind === PromptSuggestionKind::PromptPatch
                ? "dry-run: применит патч prompt_key={$suggestion->prompt_key} (base_version={$suggestion->base_version})."
                : "dry-run: создаст prc_ai_corrections(is_reusable=true) для document_type={$suggestion->document_type}.");

            return self::SUCCESS;
        }

        try {
            $applied = $this->applier->apply($suggestion);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($applied->kind === PromptSuggestionKind::PromptPatch) {
            $this->info("Предложение #{$id} применено: prompt_key={$applied->prompt_key} → version={$applied->applied_version}.");
            $this->warn('Файл изменён в рабочей копии — закоммить его и прогони ai:prompts:sync, чтобы обновить БД.');

            return self::SUCCESS;
        }

        $this->info("Предложение #{$id} применено: создана запись prc_ai_corrections(is_reusable=true).");

        return self::SUCCESS;
    }
}
