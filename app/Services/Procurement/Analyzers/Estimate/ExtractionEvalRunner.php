<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalRun;
use App\Repositories\Llm\LlmCallReadRepository;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Repositories\Procurement\Ai\EvalRunStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Llm\LlmRouteResolver;
use App\Services\Procurement\Contracts\Dto\ProgressTick;
use App\Services\Procurement\Contracts\ProgressReporter;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Прогон ТЕКУЩЕГО extraction-пайплайна (`ExtractionOrchestrator`, P6.1/P6.4) по golden-датасету
 * (`estimate:eval:run --task=extraction`, вынесено из `EvalRunCommand`, P7.4/P3.3): реальный
 * маршрут/провайдер — запускается ВРУЧНУЮ владельцем. Каждый пример — отдельный
 * `Ai\Analysis(step=extraction)` на `document_version_id` эталона; `run()` вызывается в цикле до
 * терминального исхода (checkpoint-requeue D31 здесь не self-dispatch, а прямой повтор В ТОМ ЖЕ
 * процессе). `AnalysisStoreRepository::resetForRerun()` сбрасывает найденный `Analysis` ПЕРЕД
 * каждым вызовом оркестратора — без сброса повторный прогон молча отдавал бы результат ПЕРВОГО
 * прогона без единого нового LLM-вызова.
 */
final readonly class ExtractionEvalRunner
{
    public function __construct(
        private AnalysisStoreRepository $analyses,
        private AnalysisRunStoreRepository $runs,
        private LlmRouteResolver $routes,
        private ExtractionOrchestrator $orchestrator,
        private EvalMetrics $metrics,
        private EvalRunStoreRepository $evalRuns,
        private DocumentStoreRepository $documents,
        private LlmCallReadRepository $llmCalls,
    ) {}

    /**
     * @param  Collection<int, DatasetExample>  $examples
     * @return array{run: EvalRun, aggregate: array<string, mixed>, rows: Collection<int, array{example: DatasetExample, metrics: array{overall: array{precision: float, recall: float, f1: float}, row_coverage: float}|array{error: string}}>}
     */
    public function run(Collection $examples, string $datasetVersion, ?ProgressReporter $progress = null): array
    {
        $route = $this->routes->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows);

        $run = $this->evalRuns->start([
            'document_type' => AiDocumentType::Estimate->value,
            'task' => AnalysisStep::Extraction->value,
            'dataset_version' => $datasetVersion,
            'provider' => $route->route->provider->code,
            'model' => $route->route->model,
            'prompt_version_set' => null,
            'metrics' => null,
            'ci_status' => 'pass',
            'started_at' => now(),
            'finished_at' => null,
        ]);

        $rows = [];
        $analysisIds = [];
        $total = $examples->count();
        $done = 0;

        foreach ($examples as $example) {
            $analysisId = null;
            $done++;
            $metrics = $this->evaluateExample($example, $analysisId, $progress, $done, $total);
            $rows[] = ['example' => $example, 'metrics' => $metrics];

            if ($analysisId !== null) {
                $analysisIds[] = $analysisId;
            }

            $this->evalRuns->storeResult([
                'eval_run_id' => $run->id,
                'dataset_example_id' => $example->id,
                'metric' => 'f1',
                'value' => $metrics['overall']['f1'] ?? 0.0,
                'details' => $metrics,
            ]);
        }

        $aggregate = $this->aggregate(collect($rows));

        $this->evalRuns->finish($run, [
            'metrics' => $aggregate,
            'finished_at' => now(),
            'prompt_version_set' => $this->llmCalls->resolvePromptVersionSet($analysisIds, $run->started_at),
        ]);

        return ['run' => $run, 'aggregate' => $aggregate, 'rows' => collect($rows)];
    }

    /**
     * @return array{overall: array{precision: float, recall: float, f1: float}, row_coverage: float}|array{error: string}
     */
    private function evaluateExample(DatasetExample $example, ?int &$analysisId, ?ProgressReporter $progress, int $done, int $total): array
    {
        $documentVersion = $example->documentVersion;

        if ($documentVersion === null) {
            return ['error' => 'document_version отсутствует'];
        }

        // Launch-точка (§3.5 root/document-contour.md) — КАЖДЫЙ пример это отдельный прогон
        // (mode=manual, ручной harness); `Analysis` ниже переиспользуется той же строкой между
        // прогонами `estimate:eval:run` (partial unique), `analysis_run_id` переставляется явно
        // при сбросе — тот же приём, что `FormatBenchRunner::runCell()`.
        $run = $this->runs->start($documentVersion->id, RunMode::Manual);

        try {
            $document = $this->documents->findOrFail($documentVersion->document_id);
            $analysis = $this->analyses->firstOrCreate($documentVersion->id, AnalysisStep::Extraction);
            $analysisId = $analysis->id;

            $this->analyses->resetForRerun($analysis, $run->id, AiDocumentType::Estimate);
            $analysis->refresh();

            $maxIterations = (int) config('ai.eval.max_orchestrator_iterations', 200);
            $iterations = 0;

            do {
                $needsContinuation = $this->orchestrator->run($document, $analysis);
                $analysis->refresh();
                $iterations++;
            } while ($needsContinuation && $iterations < $maxIterations);

            $predicted = (array) ($analysis->result['estimates'] ?? []);
            $gold = (array) ($example->gold['estimates'] ?? []);

            // Подстраховка терминации (§3.6, идемпотентно): `Succeeded`/`Skipped` уже
            // терминировали прогон автохуком `AnalysisStoreRepository::maybeTerminateRun()`
            // (шаг `Extraction`) — этот вызов no-op. `$maxIterations` исчерпан без терминала
            // (`analysis.status` остался `Running`) автохук НЕ сработал — прогон завершается
            // `failed` явно, а не остаётся открытым (см. `FormatBenchRunner::runCell()`).
            in_array($analysis->status, [AnalysisStatus::Succeeded, AnalysisStatus::Skipped], true)
                ? $this->runs->markSucceeded($run)
                : $this->runs->markFailed($run);

            return $this->metrics->compare(goldEstimates: $gold, predictedEstimates: $predicted);
        } catch (Throwable $e) {
            $progress?->report(new ProgressTick($done, $total, "dataset_example #{$example->id}", $e->getMessage()));
            $this->runs->markFailed($run);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param  Collection<int, array{example: DatasetExample, metrics: array{overall: array{precision: float, recall: float, f1: float}, row_coverage: float}|array{error: string}}>  $rows
     * @return array<string, mixed>
     */
    private function aggregate(Collection $rows): array
    {
        $succeeded = $rows->filter(fn (array $row): bool => ! isset($row['metrics']['error']));

        if ($succeeded->isEmpty()) {
            return ['examples' => $rows->count(), 'succeeded' => 0, 'overall' => null, 'row_coverage' => null];
        }

        return [
            'examples' => $rows->count(),
            'succeeded' => $succeeded->count(),
            'overall' => [
                'precision' => round((float) $succeeded->avg(fn (array $row): float => $row['metrics']['overall']['precision']), 4),
                'recall' => round((float) $succeeded->avg(fn (array $row): float => $row['metrics']['overall']['recall']), 4),
                'f1' => round((float) $succeeded->avg(fn (array $row): float => $row['metrics']['overall']['f1']), 4),
            ],
            'row_coverage' => round((float) $succeeded->avg(fn (array $row): float => $row['metrics']['row_coverage']), 4),
        ];
    }
}
