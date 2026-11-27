<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Console\Support\ConsoleProgressReporter;
use App\Dto\Procurement\Fixture\ItemOutcome;
use App\Services\Procurement\Analyzers\Estimate\Eval\PromptSuggestionRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Дешёвый LLM-критик промтов по эталону: по каждому `--code`/`--analysis`
 * строит diff-отчёт и для каждого `--step` предлагает патч/hint. Создаёт ТОЛЬКО записи `pending` —
 * ни одного изменения в `resources/ai/prompts/**` (это делает `ai:prompts:apply` после апрува). Команда признана сметной: вход — свидетельство критика
 * (`EstimateDiffReport`), без которого у неё нет предмета; цикл вынесен в
 * {@see PromptSuggestionRunner}.
 */
#[Description('LLM-критик промтов по diff-отчёту эталона — кладёт предложения pending в очередь')]
#[AsCommand(name: 'estimate:prompts:suggest')]
#[Signature('estimate:prompts:suggest
        {--code=* : коды эталонов}
        {--analysis= : id анализа вместо кода}
        {--step=* : шаги (PromptStep), по которым предлагать}
        {--dry-run : не вызывать LLM, показать вход критика}
        {--limit= : максимум предложений за прогон}')]
final class PromptsSuggestCommand extends Command
{
    public function handle(PromptSuggestionRunner $runner): int
    {
        $codes = array_values(array_map('strval', (array) $this->option('code')));
        $analysisOption = $this->option('analysis');
        $stepOptions = array_values(array_map('strval', (array) $this->option('step')));
        $dryRun = (bool) $this->option('dry-run');
        $limitOption = $this->option('limit');
        $limit = $limitOption !== null ? (int) $limitOption : null;

        if ($codes === [] && $analysisOption === null) {
            $this->error('Нужен хотя бы один --code=<эталон> или --analysis=<id>.');

            return self::FAILURE;
        }

        if ($stepOptions === []) {
            $this->error('Нужен хотя бы один --step=<PromptStep>.');

            return self::FAILURE;
        }

        $result = $runner->run(
            $codes,
            $analysisOption !== null ? (int) $analysisOption : null,
            $stepOptions,
            $dryRun,
            $limit,
            new ConsoleProgressReporter($this->output),
        );

        foreach ($result['outcomes'] as $outcome) {
            $this->renderOutcome($outcome);
        }

        $this->info("Создано предложений: {$result['created']}.");

        return self::SUCCESS;
    }

    private function renderOutcome(ItemOutcome $outcome): void
    {
        match ($outcome->status) {
            'rejected' => $this->error("код={$outcome->code}: {$outcome->reason}"),
            'created' => $this->info("код={$outcome->code} {$outcome->reason}."),
            default => $this->line("код={$outcome->code} {$outcome->reason}."),
        };
    }
}
