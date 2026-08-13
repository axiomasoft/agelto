<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
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
use LogicException;
use Throwable;

/**
 * Прогон оценки качества скрининга по golden-датасету (`estimate:eval:run --task=screening`,
 * вынесено из `EvalRunCommand`, root/service-layer-map.md §4) — кросс-документная ветвь
 * той же команды (карта §4 «неоднозначность 3»).
 */
final readonly class ScreeningEvalRunner
{
    public function __construct(
        private AnalysisStoreRepository $analyses,
        private AnalysisRunStoreRepository $runs,
        private LlmRouteResolver $routes,
        private ScreeningProcessor $screeningProcessor,
        private ScreeningEvalMetrics $screeningMetrics,
        private EvalRunStoreRepository $evalRuns,
        private DocumentStoreRepository $documents,
        private LlmCallReadRepository $llmCalls,
    ) {}

    /**
     * @param  Collection<int, DatasetExample>  $examples
     * @return array{run: EvalRun, aggregate: array{total: int, accuracy: float|null, confusion: array<string, array<string, int>>, false_positive_rate: float|null, false_negative_rate: float|null}, rows: Collection<int, array{example: DatasetExample, comparison: array{gold: string, predicted: string, match: bool, gold_is_estimate: bool, predicted_is_estimate: bool, code: string|null, confidence: float, classification_note: string|null}|array{error: string}}>}
     */
    public function run(Collection $examples, string $datasetVersion, ?ProgressReporter $progress = null): array
    {
        $route = $this->routes->resolve(DocumentTypeScope::Any, CoreLlmPurpose::Screening);
        $provider = $route->route->provider;

        if ($provider === null) {
            throw new LogicException('Screening eval требует route с provider.');
        }

        $run = $this->evalRuns->start([
            'document_type' => DocumentTypeScope::Any->value,
            'task' => AnalysisStep::Screening->value,
            'dataset_version' => $datasetVersion,
            'provider' => $provider->code,
            'model' => $route->route->model,
            'prompt_version_set' => null,
            'metrics' => null,
            'ci_status' => 'pass',
            'started_at' => now(),
            'finished_at' => null,
        ]);

        /** @var list<array{example: DatasetExample, comparison: array{gold: string, predicted: string, match: bool, gold_is_estimate: bool, predicted_is_estimate: bool, code: string|null, confidence: float, classification_note: string|null}|array{error: string}}> $comparisons */
        $comparisons = [];
        $analysisIds = [];
        $total = $examples->count();
        $done = 0;

        foreach ($examples as $example) {
            $analysisId = null;
            $done++;
            $comparison = $this->evaluateScreeningExample($example, $analysisId, $progress, $done, $total);
            $comparisons[] = ['example' => $example, 'comparison' => $comparison];

            if ($analysisId !== null) {
                $analysisIds[] = $analysisId;
            }

            $this->evalRuns->storeResult([
                'eval_run_id' => $run->id,
                'dataset_example_id' => $example->id,
                'metric' => 'screening_accuracy',
                'value' => ($comparison['match'] ?? false) ? 1.0 : 0.0,
                'details' => $comparison,
            ]);
        }

        /** @var list<array{gold: string, predicted: string, match: bool, gold_is_estimate: bool, predicted_is_estimate: bool}> $validComparisons */
        $validComparisons = [];

        foreach ($comparisons as $row) {
            if (! $this->hasComparison($row['comparison'])) {
                continue;
            }

            $validComparisons[] = [
                'gold' => $row['comparison']['gold'],
                'predicted' => $row['comparison']['predicted'],
                'match' => $row['comparison']['match'],
                'gold_is_estimate' => $row['comparison']['gold_is_estimate'],
                'predicted_is_estimate' => $row['comparison']['predicted_is_estimate'],
            ];
        }

        $aggregate = $this->screeningMetrics->aggregate($validComparisons);

        $startedAt = $run->started_at;

        if ($startedAt === null) {
            throw new LogicException('Созданный screening EvalRun должен иметь started_at.');
        }

        $this->evalRuns->finish($run, [
            'metrics' => $aggregate,
            'finished_at' => now(),
            'prompt_version_set' => $this->llmCalls->resolvePromptVersionSet($analysisIds, $startedAt),
        ]);

        return ['run' => $run, 'aggregate' => $aggregate, 'rows' => collect($comparisons)];
    }

    /**
     * @return array{gold: string, predicted: string, match: bool, gold_is_estimate: bool,
     *     predicted_is_estimate: bool, code: string|null, confidence: float, classification_note: string|null}|array{error: string}
     */
    private function evaluateScreeningExample(DatasetExample $example, ?int &$analysisId, ?ProgressReporter $progress, int $done, int $total): array
    {
        $documentVersion = $example->documentVersion;

        if ($documentVersion === null) {
            return ['error' => 'document_version отсутствует'];
        }

        // Launch-точка (§3.5 root/document-contour.md) — mode=manual; в отличие от extraction,
        // автохук `AnalysisStoreRepository::maybeTerminateRun()` НЕ терминирует прогон на шаге
        // `Screening` (ветвление принадлежит `ScreeningOutcomeHandler`, которого этот
        // standalone-eval не вызывает) — терминация здесь ЯВНАЯ.
        $run = $this->runs->start($documentVersion->id, RunMode::Manual);

        try {
            $document = $this->documents->findOrFail($documentVersion->document_id);
            $analysis = $this->analyses->firstOrCreate($documentVersion->id, AnalysisStep::Screening);
            $analysisId = $analysis->id;

            // Тот же приём, что у extraction-ветки: без сброса `firstOrCreate()` вернул бы
            // терминальный `Analysis` прошлого прогона, и повторный прогон отдавал бы старый
            // вердикт без единого нового вызова `ScreeningProcessor`.
            $this->analyses->resetForRerun($analysis, $run->id, null);
            $analysis->refresh();

            $prediction = $this->screeningProcessor->run($document, $analysis);

            if ($prediction === null) {
                return ['error' => 'скрининг завершился без validated provider result'];
            }

            $this->runs->markSucceeded($run);

            $comparison = $this->screeningMetrics->compare($this->goldPayload($example->gold?->toArray() ?? []), $prediction);

            return [
                ...$comparison,
                'code' => $example->fixture?->code,
                'confidence' => $prediction->confidence,
                'classification_note' => $prediction->classification_note,
            ];
        } catch (Throwable $e) {
            $progress?->report(new ProgressTick($done, $total, "dataset_example #{$example->id}", $e->getMessage()));
            $this->runs->markFailed($run);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function goldPayload(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new LogicException('Gold payload скрининга должен быть объектом с именованными полями.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array{gold: string, predicted: string, match: bool, gold_is_estimate: bool, predicted_is_estimate: bool, code: string|null, confidence: float, classification_note: string|null}|array{error: string}  $comparison
     *
     * @phpstan-assert-if-true array{gold: string, predicted: string, match: bool, gold_is_estimate: bool, predicted_is_estimate: bool, code: string|null, confidence: float, classification_note: string|null} $comparison
     */
    private function hasComparison(array $comparison): bool
    {
        return ! isset($comparison['error']);
    }
}
