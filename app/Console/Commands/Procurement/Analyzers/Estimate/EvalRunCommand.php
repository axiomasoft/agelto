<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Console\Support\ConsoleProgressReporter;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Exceptions\Procurement\OperationFailed;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalRun;
use App\Repositories\Procurement\DatasetExampleReadRepository;
use App\Repositories\Procurement\EvalRunStoreRepository;
use App\Services\Procurement\Analyzers\Estimate\EvalSplitPlanner;
use App\Services\Procurement\Analyzers\Estimate\ExtractionEvalRunner;
use App\Services\Procurement\Screening\ScreeningEvalRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Прогон ТЕКУЩЕГО extraction-пайплайна (`ExtractionOrchestrator`, P6.1/P6.4) по golden-датасету
 * (P7.4, ТЗ 04 §5): реальный маршрут/провайдер (FakeLlm недопустим, Implementation Rules) —
 * запускается ВРУЧНУЮ владельцем. В CI — только `--dry-run` (или пустой датасет, тот же исход):
 * пишет EvalRun-заглушку без единого LLM-вызова. Логика цикла оркестратора вынесена в
 * {@see ExtractionEvalRunner}/{@see ScreeningEvalRunner} (P3.3, `root/service-layer-map.md` §3).
 */
final class EvalRunCommand extends Command
{
    protected $signature = 'estimate:eval:run
        {--task=extraction : task датасета для прогона}
        {--dataset-version= : метка версии датасета для EvalRun (default — config(ai.eval.dataset_version))}
        {--limit= : ограничить число эталонов}
        {--dry-run : не делать реальных LLM-вызовов — только зафиксировать пустой прогон}
        {--split=all : подвыборка датасета tune|holdout|all — членство в holdout авторитетно (D120)}
        {--write-splits : пересобрать estimates/eval-splits.json по правилу отбора (D119) и выйти, без прогона}';

    protected $description = 'Прогон extraction-пайплайна по golden-датасету, метрики precision/recall/F1 + row coverage';

    public function __construct(
        private readonly DatasetExampleReadRepository $datasetExamples,
        private readonly EvalRunStoreRepository $evalRuns,
        private readonly EvalSplitPlanner $splitPlanner,
        private readonly ExtractionEvalRunner $extractionRunner,
        private readonly ScreeningEvalRunner $screeningRunner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('write-splits')) {
            try {
                $result = $this->splitPlanner->plan();
            } catch (OperationFailed $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info('estimates/eval-splits.json переписан: holdout=['.implode(', ', $result['holdout']).'] tune=['.implode(', ', $result['tune']).']');

            return self::SUCCESS;
        }

        $task = (string) $this->option('task');

        if (! in_array($task, ['extraction', 'screening'], true)) {
            $this->error("Неизвестный --task={$task} (ожидается extraction|screening).");

            return self::FAILURE;
        }

        $split = (string) $this->option('split');

        if (! in_array($split, ['tune', 'holdout', 'all'], true)) {
            $this->error("Неизвестный --split={$split} (ожидается tune|holdout|all).");

            return self::FAILURE;
        }

        $datasetVersion = (string) ($this->option('dataset-version') ?: config('ai.eval.dataset_version', 'v1'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $holdoutCodes = $split !== 'all' ? $this->splitPlanner->loadHoldoutCodes() : [];

        $examples = $this->datasetExamples->forTask($task, $split, $holdoutCodes, $limit);

        if ($this->option('dry-run') || $examples->isEmpty()) {
            $this->info($examples->isEmpty()
                ? 'Датасет пуст — dry-run по факту (ни одного LLM-вызова).'
                : 'Dry-run: реальные LLM-вызовы пропущены по флагу --dry-run.');

            $run = $this->evalRuns->start([
                'document_type' => $task === 'screening' ? DocumentTypeScope::Any->value : AiDocumentType::Estimate->value,
                'task' => $task,
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

        $progress = new ConsoleProgressReporter($this->output);

        if ($task === 'screening') {
            $result = $this->screeningRunner->run($examples, $datasetVersion, $progress);
            $this->renderScreeningReport($result['run'], $result['aggregate'], $result['rows']);

            return self::SUCCESS;
        }

        $result = $this->extractionRunner->run($examples, $datasetVersion, $progress);
        $this->renderReport($result['run'], $result['aggregate'], $result['rows']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @param  Collection<int, array{example: DatasetExample, metrics: array{overall: array{precision: float, recall: float, f1: float}, row_coverage: float}|array{error: string}}>  $rows
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

    /**
     * @param  array{total: int, accuracy: float|null, confusion: array<string, array<string, int>>,
     *     false_positive_rate: float|null, false_negative_rate: float|null}  $aggregate
     */
    private function renderScreeningReport(EvalRun $run, array $aggregate, Collection $rows): void
    {
        $this->info("EvalRun #{$run->id} (dataset_version={$run->dataset_version}, {$run->provider}/{$run->model}): screening — {$aggregate['total']} эталонов.");

        if ($aggregate['accuracy'] !== null) {
            $this->line(sprintf(
                'Итого: accuracy=%.4f · false_positive_rate=%s · false_negative_rate=%s · всего=%d',
                $aggregate['accuracy'],
                $aggregate['false_positive_rate'] === null ? 'n/a' : sprintf('%.4f', $aggregate['false_positive_rate']),
                $aggregate['false_negative_rate'] === null ? 'n/a' : sprintf('%.4f', $aggregate['false_negative_rate']),
                $aggregate['total'],
            ));
        }

        if ($aggregate['confusion'] !== []) {
            $confusionRows = [];

            foreach ($aggregate['confusion'] as $gold => $predictedCounts) {
                foreach ($predictedCounts as $predicted => $count) {
                    $confusionRows[] = [$gold, $predicted, $count];
                }
            }

            $this->table(['ожидали', 'получили', 'кол-во'], $confusionRows);
        }

        $mismatches = $rows->filter(fn (array $row): bool => isset($row['comparison']['match']) && ! $row['comparison']['match']);

        if ($mismatches->isEmpty()) {
            return;
        }

        $this->table(
            ['код эталона', 'ожидали', 'получили', 'confidence', 'ignore_reason'],
            $mismatches->map(fn (array $row): array => [
                $row['comparison']['code'] ?? "#{$row['example']->id}",
                $row['comparison']['gold'],
                $row['comparison']['predicted'],
                $row['comparison']['confidence'],
                $row['comparison']['ignore_reason'] ?? '—',
            ])->all(),
        );
    }
}
