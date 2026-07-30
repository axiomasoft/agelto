<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\RunMode;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalRun;
use App\Repositories\Llm\LlmCallReadRepository;
use App\Repositories\Procurement\AnalysisRunStoreRepository;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Repositories\Procurement\EvalRunStoreRepository;
use App\Services\Llm\AiRouteResolver;
use App\Services\Procurement\Contracts\Dto\ProgressTick;
use App\Services\Procurement\Contracts\ProgressReporter;
use Illuminate\Support\Collection;
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
        private AiRouteResolver $routes,
        private ScreeningProcessor $screeningProcessor,
        private ScreeningEvalMetrics $screeningMetrics,
        private EvalRunStoreRepository $evalRuns,
        private DocumentStoreRepository $documents,
        private LlmCallReadRepository $llmCalls,
    ) {}

    /**
     * @param  Collection<int, DatasetExample>  $examples
     * @return array{run: EvalRun, aggregate: array{total: int, accuracy: float|null, confusion: array<string, array<string, int>>, false_positive_rate: float|null, false_negative_rate: float|null}, rows: Collection}
     */
    public function run(Collection $examples, string $datasetVersion, ?ProgressReporter $progress = null): array
    {
        $route = $this->routes->resolve(DocumentTypeScope::Any, CoreLlmPurpose::Screening);

        $run = $this->evalRuns->start([
            'document_type' => DocumentTypeScope::Any->value,
            'task' => AnalysisStep::Screening->value,
            'dataset_version' => $datasetVersion,
            'provider' => $route->route->provider->code,
            'model' => $route->route->model,
            'prompt_version_set' => null,
            'metrics' => null,
            'ci_status' => 'pass',
            'started_at' => now(),
            'finished_at' => null,
        ]);

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

        $validComparisons = array_values(array_filter(
            array_column($comparisons, 'comparison'),
            static fn (array $c): bool => ! isset($c['error']),
        ));

        $aggregate = $this->screeningMetrics->aggregate($validComparisons);

        $this->evalRuns->finish($run, [
            'metrics' => $aggregate,
            'finished_at' => now(),
            'prompt_version_set' => $this->llmCalls->resolvePromptVersionSet($analysisIds, $run->started_at),
        ]);

        return ['run' => $run, 'aggregate' => $aggregate, 'rows' => collect($comparisons)];
    }

    /**
     * @return array{gold: string, predicted: string, match: bool, gold_is_estimate: bool,
     *     predicted_is_estimate: bool, code: string|null, confidence: float, ignore_reason: string|null}|array{error: string}
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

            $this->screeningProcessor->run($document, $analysis);
            $analysis->refresh();
            $this->runs->markSucceeded($run);

            $documentType = AiDocumentType::tryFrom((string) $analysis->document_type);

            if ($documentType === null) {
                $detail = json_encode($analysis->result, JSON_UNESCAPED_UNICODE);

                return ['error' => "скрининг не вернул document_type (Analysis.result: {$detail})"];
            }

            $prediction = new ScreeningResult(
                document_type: $documentType,
                confidence: (float) ($analysis->result['confidence'] ?? 0.0),
                description: $analysis->result['description'] ?? null,
                tags: (array) ($analysis->result['tags'] ?? []),
                page_count_estimate: $analysis->result['page_count_estimate'] ?? null,
                ignore_reason: $analysis->result['ignore_reason'] ?? null,
            );

            $comparison = $this->screeningMetrics->compare($example->gold?->toArray() ?? [], $prediction);

            return [
                ...$comparison,
                'code' => $example->fixture?->code,
                'confidence' => $prediction->confidence,
                'ignore_reason' => $prediction->ignore_reason,
            ];
        } catch (Throwable $e) {
            $progress?->report(new ProgressTick($done, $total, "dataset_example #{$example->id}", $e->getMessage()));
            $this->runs->markFailed($run);

            return ['error' => $e->getMessage()];
        }
    }
}
