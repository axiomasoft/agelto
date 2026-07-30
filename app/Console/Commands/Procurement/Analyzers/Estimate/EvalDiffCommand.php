<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Console\Concerns\RendersReport;
use App\Exceptions\Procurement\OperationFailed;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Procurement\Analyzers\Estimate\EstimateDiffReport;
use App\Services\Procurement\Fixture\FixtureCaseResolver;
use Illuminate\Console\Command;

/**
 * Диагностическая обёртка над {@see EstimateDiffReport} (P7.3, D62): по коду эталона резолвит
 * gold-файл корпуса и АКТИВНЫЙ `Analysis(step=extraction)` рабочей копии эталона, либо берёт
 * анализ напрямую по `--analysis` — резолв вынесен в {@see FixtureCaseResolver} (P3.3, общий с
 * `estimate:prompts:suggest`). НИ ОДНОГО LLM-вызова не делает — читает уже полученный
 * `analysis.result`, не запускает оркестратор (в отличие от `estimate:eval:run`).
 */
final class EvalDiffCommand extends Command
{
    use RendersReport;

    protected $signature = 'estimate:eval:diff
        {--code= : код эталона}
        {--analysis= : id анализа вместо кода}
        {--format=json : формат вывода (json|md)}
        {--out= : путь файла (репо-относительный)}
        {--limit-rows=200 : ограничение списка построчных расхождений (0 — без лимита)}';

    protected $description = 'Детерминированный diff-отчёт «gold vs предсказание» по эталону — без единого LLM-вызова';

    public function __construct(
        private readonly EstimateDiffReport $diffReport,
        private readonly FixtureCaseResolver $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $code = $this->option('code') !== null ? (string) $this->option('code') : null;
        $analysisId = $this->option('analysis') !== null ? (int) $this->option('analysis') : null;
        $format = (string) $this->option('format');
        $limitRowsOption = (int) $this->option('limit-rows');
        $limitRows = $limitRowsOption > 0 ? $limitRowsOption : null;

        try {
            $this->guardFormat($format);

            if ($code === null && $analysisId === null) {
                $this->error('Нужен --code=<эталон> или --analysis=<id>.');

                return self::FAILURE;
            }

            [$code, $analysis, $gold] = $analysisId !== null
                ? $this->resolver->resolveByAnalysisId($analysisId)
                : $this->resolver->resolveByCode((string) $code);
        } catch (OperationFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $goldEstimates = (array) ($gold['estimates'] ?? []);
        $predictedEstimates = (array) ($analysis->result['estimates'] ?? []);

        $report = $this->diffReport->build($goldEstimates, $predictedEstimates, $limitRows);
        $rendered = $format === 'json' ? $this->renderJson($report->toArray()) : $this->renderMarkdown($code, $analysis, $report->toArray());

        $this->writeArtifact($this->option('out'), $rendered);

        $this->line($rendered);
        $this->info("Вердикт: {$report->verdict}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderMarkdown(string $code, Analysis $analysis, array $report): string
    {
        $lines = [];
        $lines[] = "# Diff-отчёт: {$code} (Analysis #{$analysis->id})";
        $lines[] = '';
        $lines[] = "**Вердикт:** {$report['verdict']}";
        $lines[] = '';
        $lines[] = sprintf('Показано строчных расхождений %d из %d.', $report['truncated']['shown'], $report['truncated']['total']);
        $lines[] = '';

        foreach (['rows' => 'Строки', 'passport' => 'Паспорт', 'totals' => 'Итоги'] as $key => $title) {
            /** @var list<array<string, mixed>> $entries */
            $entries = $report[$key];
            $lines[] = "## {$title} ({$this->count($entries)})";
            $lines[] = '';

            if ($entries === []) {
                $lines[] = 'Расхождений нет.';
                $lines[] = '';

                continue;
            }

            $lines[] = '| смета | строка | вид | поле | gold | предсказание |';
            $lines[] = '|--:|--:|:--|:--|:--|:--|';

            foreach ($entries as $entry) {
                $lines[] = sprintf(
                    '| %d | %s | %s | %s | %s | %s |',
                    $entry['estimate_index'],
                    $entry['row_index'] ?? '—',
                    $entry['kind'],
                    $entry['field'] ?? '—',
                    $this->scalar($entry['gold']),
                    $this->scalar($entry['predicted']),
                );
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<mixed>  $entries
     */
    private function count(array $entries): int
    {
        return count($entries);
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
        }

        return (string) $value;
    }
}
