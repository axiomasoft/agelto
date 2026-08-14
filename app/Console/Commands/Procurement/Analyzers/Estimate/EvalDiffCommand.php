<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Console\Concerns\RendersReport;
use App\Dto\Procurement\Analyzers\Estimate\Diff\DiffEntry;
use App\Dto\Procurement\Analyzers\Estimate\Diff\DiffReport;
use App\Exceptions\Procurement\InvalidEvalDiffPayload;
use App\Exceptions\Procurement\OperationFailed;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Procurement\Analyzers\Estimate\EstimateDiffReport;
use App\Services\Procurement\Fixture\FixtureCaseResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Диагностическая обёртка над {@see EstimateDiffReport} (P7.3, D62): по коду эталона резолвит
 * gold-файл корпуса и АКТИВНЫЙ `Analysis(step=extraction)` рабочей копии эталона, либо берёт
 * анализ напрямую по `--analysis` — резолв вынесен в {@see FixtureCaseResolver} (P3.3, общий с
 * `estimate:prompts:suggest`). НИ ОДНОГО LLM-вызова не делает — читает уже полученный
 * `analysis.result`, не запускает оркестратор (в отличие от `estimate:eval:run`).
 */
#[Description('Детерминированный diff-отчёт «gold vs предсказание» по эталону — без единого LLM-вызова')]
#[Signature('estimate:eval:diff
        {--code= : код эталона}
        {--analysis= : id анализа вместо кода}
        {--format=json : формат вывода (json|md)}
        {--out= : путь файла (репо-относительный)}
        {--limit-rows=200 : ограничение списка построчных расхождений (0 — без лимита)}')]
final class EvalDiffCommand extends Command
{
    use RendersReport;

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

        $goldEstimates = $this->estimates($gold['estimates'] ?? [], 'gold');
        $predictedEstimates = $this->estimates($analysis->result['estimates'] ?? [], 'analysis result');

        $report = $this->diffReport->build($goldEstimates, $predictedEstimates, $limitRows);
        $rendered = $format === 'json' ? $this->renderJson($report->toArray()) : $this->renderMarkdown($code, $analysis, $report);

        $this->writeArtifact($this->option('out'), $rendered);

        $this->line($rendered);
        $this->info("Вердикт: {$report->verdict}");

        return self::SUCCESS;
    }

    private function renderMarkdown(string $code, Analysis $analysis, DiffReport $report): string
    {
        $lines = [];
        $lines[] = "# Diff-отчёт: {$code} (Analysis #{$analysis->id})";
        $lines[] = '';
        $lines[] = "**Вердикт:** {$report->verdict}";
        $lines[] = '';
        $lines[] = sprintf('Показано строчных расхождений %d из %d.', $report->truncated['shown'], $report->truncated['total']);
        $lines[] = '';

        foreach (['rows' => 'Строки', 'passport' => 'Паспорт', 'totals' => 'Итоги'] as $key => $title) {
            /** @var list<DiffEntry> $entries */
            $entries = $report->{$key};
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
                    $entry->estimateIndex,
                    $entry->rowIndex ?? '—',
                    $entry->kind,
                    $entry->field ?? '—',
                    $this->scalar($entry->gold),
                    $this->scalar($entry->predicted),
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

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
    }

    /** @return list<array<string, mixed>> */
    private function estimates(mixed $value, string $source): array
    {
        if (! is_array($value)) {
            throw new InvalidEvalDiffPayload("{$source} estimates должен быть списком объектов.");
        }

        $estimates = [];

        foreach ($value as $estimate) {
            if (! is_array($estimate)) {
                throw new InvalidEvalDiffPayload("{$source} estimates должен содержать только объекты.");
            }

            foreach (array_keys($estimate) as $key) {
                if (! is_string($key)) {
                    throw new InvalidEvalDiffPayload("{$source} estimates содержит нестроковый ключ.");
                }
            }

            /** @var array<string, mixed> $estimate */
            $estimates[] = $estimate;
        }

        return $estimates;
    }
}
