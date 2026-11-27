<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Console\Concerns\RendersReport;
use App\Exceptions\Procurement\OperationFailed;
use App\Services\Procurement\Analyzers\Estimate\Eval\EstimateDiffReport;
use App\Services\Procurement\Fixture\FixtureCaseResolver;
use App\Services\Procurement\Tuning\EvalConsolePresenter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Диагностическая обёртка над {@see EstimateDiffReport}: по коду эталона резолвит
 * gold-файл корпуса и АКТИВНЫЙ `Analysis(step=extraction)` рабочей копии эталона, либо берёт
 * анализ напрямую по `--analysis` — резолв вынесен в {@see FixtureCaseResolver} (общий с
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

    public function handle(EvalConsolePresenter $presenter = new EvalConsolePresenter): int
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

        $goldEstimates = $presenter->extractEstimates($gold['estimates'] ?? [], 'gold');
        $predictedEstimates = $presenter->extractEstimates($analysis->result['estimates'] ?? [], 'analysis result');

        $report = $this->diffReport->build($goldEstimates, $predictedEstimates, $limitRows);
        $rendered = $format === 'json'
            ? $this->renderJson($report->toArray())
            : $presenter->renderDiffMarkdown($code, $analysis, $report);

        $this->writeArtifact($this->option('out'), $rendered);

        $this->line($rendered);
        $this->info("Вердикт: {$report->verdict}");

        return self::SUCCESS;
    }
}
