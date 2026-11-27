<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Exceptions\Procurement\OperationFailed;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalRun;
use App\Repositories\Procurement\Ai\DatasetExampleReadRepository;
use App\Repositories\Procurement\Ai\EvalRunStoreRepository;
use App\Services\Procurement\Analyzers\Estimate\Eval\EvalSplitPlanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use UnexpectedValueException;

/**
 * Прогон ТЕКУЩЕГО extraction-пайплайна (`ExtractionOrchestrator`) по golden-датасету
 * (ТЗ 04 §5): реальный маршрут/провайдер (FakeLlm недопустим) —
 * запускается ВРУЧНУЮ владельцем. В CI — только `--dry-run` (или пустой датасет, тот же исход):
 * пишет EvalRun-заглушку без единого LLM-вызова. Логика цикла оркестратора вынесена в
 * {@see ExtractionEvalRunner}/{@see ScreeningEvalRunner}.
 *
 * @phpstan-type ExtractionMetrics array{overall: array{precision: float, recall: float, f1: float}, row_coverage: float}|array{error: string}
 * @phpstan-type ExtractionRow array{example: DatasetExample, metrics: ExtractionMetrics}
 * @phpstan-type ExtractionAggregate array{examples: int, succeeded: int, overall: array{precision: float, recall: float, f1: float}|null, row_coverage: float|null}
 * @phpstan-type ScreeningComparison array{gold: string, predicted: string, match: bool, gold_is_estimate: bool, predicted_is_estimate: bool, code: string|null, confidence: float, classification_note: string|null}|array{error: string}
 * @phpstan-type ScreeningRow array{example: DatasetExample, comparison: ScreeningComparison}
 * @phpstan-type ScreeningAggregate array{total: int, accuracy: float|null, confusion: array<string, array<string, int>>, false_positive_rate: float|null, false_negative_rate: float|null}
 */
#[Description('Прогон extraction-пайплайна по golden-датасету, метрики precision/recall/F1 + row coverage')]
#[AsCommand(name: 'estimate:eval:run')]
#[Signature('estimate:eval:run
        {--task=extraction : task датасета для прогона}
        {--dataset-version= : метка версии датасета для EvalRun (default — config(ai.eval.dataset_version))}
        {--limit= : ограничить число эталонов}
        {--dry-run : не делать реальных LLM-вызовов — только зафиксировать пустой прогон}
        {--split=all : подвыборка датасета tune|holdout|all — членство в holdout авторитетно}
        {--write-splits : пересобрать estimates/eval-splits.json по правилу отбора и выйти, без прогона}')]
final class EvalRunCommand extends Command
{
    public function __construct(
        private readonly DatasetExampleReadRepository $datasetExamples,
        private readonly EvalRunStoreRepository $evalRuns,
        private readonly EvalSplitPlanner $splitPlanner,
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

        $task = $this->optionString('task');

        if (! in_array($task, ['extraction', 'screening'], true)) {
            $this->error("Неизвестный --task={$task} (ожидается extraction|screening).");

            return self::FAILURE;
        }

        $split = $this->optionString('split');

        if (! in_array($split, ['tune', 'holdout', 'all'], true)) {
            $this->error("Неизвестный --split={$split} (ожидается tune|holdout|all).");

            return self::FAILURE;
        }

        $datasetVersion = $this->option('dataset-version');
        $datasetVersion = $datasetVersion === null || $datasetVersion === ''
            ? config('ai.eval.dataset_version', 'v1')
            : $datasetVersion;

        if (! is_string($datasetVersion)) {
            throw new UnexpectedValueException('Значение --dataset-version и config(ai.eval.dataset_version) должно быть строкой.');
        }

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

        $this->error('Unsupported: live eval requires a registered durable provider owner. Use --dry-run.');

        return self::FAILURE;
    }

    private function optionString(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            throw new UnexpectedValueException("Опция --{$name} должна быть строкой.");
        }

        return $value;
    }
}
