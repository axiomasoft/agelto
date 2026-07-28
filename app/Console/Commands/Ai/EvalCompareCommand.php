<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Models\Procurement\Ai\EvalRun;
use App\Services\Procurement\Tuning\EvalRunComparator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Сравнение двух `EvalRun` по критерию приёмки патча промта (P7.5, D63 + правило отбора
 * D119/D120): дельты `overall.f1`/`row_coverage`/`per_field` на подвыборках `tune`/`holdout` и
 * дельта `f1` по каждому эталону, вердикт `accept`/`reject` — {@see EvalRunComparator}. НИ ОДНОГО
 * LLM-вызова не делает — читает уже посчитанные `prc_ai_eval_results` двух прогонов.
 */
final class EvalCompareCommand extends Command
{
    protected $signature = 'ai:eval:compare
        {--base= : id базового прогона (EvalRun)}
        {--candidate= : id прогона-кандидата (EvalRun)}
        {--format=json : формат вывода (json|md)}
        {--out= : путь файла (репо-относительный)}';

    protected $description = 'Сравнение двух EvalRun по критерию приёмки патча промта (tune/holdout, D63)';

    public function __construct(private readonly EvalRunComparator $comparator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['json', 'md'], true)) {
            $this->error("Неизвестный --format={$format} (ожидается json|md).");

            return self::FAILURE;
        }

        $baseOption = $this->option('base');
        $candidateOption = $this->option('candidate');

        if ($baseOption === null || $candidateOption === null) {
            $this->error('Нужны --base=<id прогона> и --candidate=<id прогона>.');

            return self::FAILURE;
        }

        $base = EvalRun::query()->find((int) $baseOption);
        $candidate = EvalRun::query()->find((int) $candidateOption);

        if ($base === null || $candidate === null) {
            $this->error('EvalRun не найден — проверь --base/--candidate.');

            return self::FAILURE;
        }

        try {
            $result = $this->comparator->compare($base, $candidate);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rendered = $format === 'json' ? $this->renderJson($result) : $this->renderMarkdown($result);

        $out = $this->option('out');

        if ($out !== null) {
            file_put_contents(base_path((string) $out), $rendered);
        }

        $this->line($rendered);
        $this->info("Вердикт: {$result['verdict']}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderJson(array $result): string
    {
        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderMarkdown(array $result): string
    {
        $lines = [];
        $lines[] = "# Сравнение EvalRun #{$result['base_run_id']} → #{$result['candidate_run_id']}";
        $lines[] = '';
        $lines[] = "**Вердикт:** {$result['verdict']}";
        $lines[] = '';

        if ($result['reasons'] !== []) {
            $lines[] = '## Причины отказа';
            $lines[] = '';

            foreach ($result['reasons'] as $reason) {
                $lines[] = "- {$reason}";
            }

            $lines[] = '';
        }

        $lines[] = '## Подвыборки';
        $lines[] = '';
        $lines[] = '| подвыборка | base.f1 | candidate.f1 | Δf1 | base.row_coverage | candidate.row_coverage | Δrow_coverage |';
        $lines[] = '|:--|--:|--:|--:|--:|--:|--:|';

        foreach ($result['subsamples'] as $name => $subsample) {
            $lines[] = sprintf(
                '| %s | %.4f | %.4f | %.4f | %.4f | %.4f | %.4f |',
                $name,
                $subsample['base']['f1'],
                $subsample['candidate']['f1'],
                $subsample['delta']['f1'],
                $subsample['base']['row_coverage'],
                $subsample['candidate']['row_coverage'],
                $subsample['delta']['row_coverage'],
            );
        }

        $lines[] = '';
        $lines[] = '## Дельта f1 по эталону';
        $lines[] = '';
        $lines[] = '| код эталона | Δf1 |';
        $lines[] = '|:--|--:|';

        foreach ($result['per_fixture'] as $code => $delta) {
            $lines[] = sprintf('| %s | %.4f |', $code, $delta);
        }

        return implode("\n", $lines)."\n";
    }
}
