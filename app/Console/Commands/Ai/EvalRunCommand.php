<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\LlmPurpose;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Services\Llm\AiRouteResolver;
use App\Services\Procurement\Extraction\EvalMetrics;
use App\Services\Procurement\Extraction\ExtractionOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Прогон ТЕКУЩЕГО extraction-пайплайна (`ExtractionOrchestrator`, P6.1/P6.4) по golden-датасету
 * (P7.4, ТЗ 04 §5): реальный маршрут/провайдер (FakeLlm недопустим, Implementation Rules) —
 * запускается ВРУЧНУЮ владельцем. В CI — только `--dry-run` (или пустой датасет, тот же исход):
 * пишет EvalRun-заглушку без единого LLM-вызова.
 *
 * Каждый пример — отдельный `Ai\Analysis(step=extraction)` на `document_version_id` эталона;
 * `run()` вызывается в цикле до терминального исхода (checkpoint-requeue D31 здесь не
 * self-dispatch, а прямой повтор В ТОМ ЖЕ процессе — команда синхронна, очереди не участвуют).
 * Метрики (`EvalMetrics`) сравнивают `analysis.result.estimates[]` (сырой выход оркестратора,
 * ДО `EstimatePersister`) с `gold.estimates[]` — сознательно НЕ через `prc_estimates`
 * (`EstimatePersister` пропускает документы без `procurement_id`, D22/P6.3 Known Deviations;
 * эталоны существуют вне закупки по построению этого item'а).
 */
final class EvalRunCommand extends Command
{
    protected $signature = 'ai:eval:run
        {--task=extraction : task датасета для прогона}
        {--dataset-version= : метка версии датасета для EvalRun (default — config(ai.eval.dataset_version))}
        {--limit= : ограничить число эталонов}
        {--dry-run : не делать реальных LLM-вызовов — только зафиксировать пустой прогон}';

    protected $description = 'Прогон extraction-пайплайна по golden-датасету, метрики precision/recall/F1 + row coverage';

    public function __construct(
        private readonly AnalysisStoreRepository $analyses,
        private readonly AiRouteResolver $routes,
        private readonly ExtractionOrchestrator $orchestrator,
        private readonly EvalMetrics $metrics,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $task = (string) $this->option('task');
        $datasetVersion = (string) ($this->option('dataset-version') ?: config('ai.eval.dataset_version', 'v1'));

        $query = DatasetExample::query()->where('task', $task);

        if ($this->option('limit') !== null) {
            $query->limit((int) $this->option('limit'));
        }

        $examples = $query->get();

        if ($this->option('dry-run') || $examples->isEmpty()) {
            $this->info($examples->isEmpty()
                ? 'Датасет пуст — dry-run по факту (ни одного LLM-вызова).'
                : 'Dry-run: реальные LLM-вызовы пропущены по флагу --dry-run.');

            $run = EvalRun::query()->create([
                'dataset_version' => $datasetVersion,
                'provider' => 'dry-run',
                'model' => 'dry-run',
                'prompt_version_set' => null,
                'metrics' => null,
                'ci_status' => 'pass',
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $this->info("EvalRun #{$run->id} записан (dry-run).");

            return self::SUCCESS;
        }

        $route = $this->routes->resolve('estimate', LlmPurpose::ExtractRows);

        $run = EvalRun::query()->create([
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

        foreach ($examples as $example) {
            $metrics = $this->evaluateExample($example);
            $rows[] = ['example' => $example, 'metrics' => $metrics];

            EvalResult::query()->create([
                'eval_run_id' => $run->id,
                'dataset_example_id' => $example->id,
                'metric' => 'f1',
                'value' => $metrics['overall']['f1'] ?? 0.0,
                'details' => $metrics,
            ]);
        }

        $aggregate = $this->aggregate(collect($rows));
        $run->update(['metrics' => $aggregate, 'finished_at' => now()]);

        $this->renderReport($run, $aggregate, collect($rows));

        return self::SUCCESS;
    }

    /**
     * @return array{overall: array{precision: float, recall: float, f1: float}, row_coverage: float}|array{error: string}
     */
    private function evaluateExample(DatasetExample $example): array
    {
        $documentVersion = $example->documentVersion;

        if ($documentVersion === null) {
            return ['error' => 'document_version отсутствует'];
        }

        try {
            $document = Document::query()->findOrFail($documentVersion->document_id);
            $analysis = $this->analyses->firstOrCreate($documentVersion->id, AnalysisStep::Extraction);

            if ($analysis->document_type === null) {
                $this->analyses->update($analysis, ['document_type' => 'estimate']);
                $analysis->refresh();
            }

            $maxIterations = (int) config('ai.eval.max_orchestrator_iterations', 200);
            $iterations = 0;

            do {
                $needsContinuation = $this->orchestrator->run($document, $analysis);
                $analysis->refresh();
                $iterations++;
            } while ($needsContinuation && $iterations < $maxIterations);

            $predicted = (array) ($analysis->result['estimates'] ?? []);
            $gold = (array) ($example->gold['estimates'] ?? []);

            return $this->metrics->compare(goldEstimates: $gold, predictedEstimates: $predicted);
        } catch (Throwable $e) {
            $this->error("dataset_example #{$example->id}: {$e->getMessage()}");

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param  Collection<int, array{example: DatasetExample, metrics: array<string, mixed>}>  $rows
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

    /**
     * @param  array<string, mixed>  $aggregate
     * @param  Collection<int, array{example: DatasetExample, metrics: array<string, mixed>}>  $rows
     */
    private function renderReport(EvalRun $run, array $aggregate, Collection $rows): void
    {
        $this->info("EvalRun #{$run->id} (dataset_version={$run->dataset_version}, {$run->provider}/{$run->model}): {$aggregate['succeeded']}/{$aggregate['examples']} эталонов обработано.");

        if ($aggregate['overall'] !== null) {
            $this->line(sprintf(
                'Итого: precision=%.4f recall=%.4f f1=%.4f row_coverage=%.4f',
                $aggregate['overall']['precision'],
                $aggregate['overall']['recall'],
                $aggregate['overall']['f1'],
                $aggregate['row_coverage'],
            ));
        }

        $worst = $rows
            ->filter(fn (array $row): bool => ! isset($row['metrics']['error']))
            ->sortBy(fn (array $row): float => $row['metrics']['overall']['f1'])
            ->take(10);

        if ($worst->isEmpty()) {
            return;
        }

        $this->table(
            ['dataset_example_id', 'f1', 'precision', 'recall', 'row_coverage'],
            $worst->map(fn (array $row): array => [
                $row['example']->id,
                $row['metrics']['overall']['f1'],
                $row['metrics']['overall']['precision'],
                $row['metrics']['overall']['recall'],
                $row['metrics']['row_coverage'],
            ])->all(),
        );
    }
}
