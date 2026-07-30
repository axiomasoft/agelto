<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\GoldSource;
use App\Enums\Procurement\RunMode;
use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\AnalysisRunStoreRepository;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Services\Llm\AiRouteResolver;
use App\Services\Procurement\Analyzers\Estimate\EvalMetrics;
use App\Services\Procurement\Analyzers\Estimate\ExtractionOrchestrator;
use App\Services\Procurement\Screening\ScreeningEvalMetrics;
use App\Services\Procurement\Screening\ScreeningProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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
 * Уникальность `(document_version_id, step)` не даёт завести НОВУЮ строку на повторный прогон —
 * `evaluateExample()` явно СБРАСЫВАЕТ найденный `Analysis` (status/result/progress/usage и т.д.)
 * ПЕРЕД каждым вызовом оркестратора (ревью-фикс P7.4, HIGH), иначе повторный `ai:eval:run` на том
 * же датасете молча отдавал бы результат ПЕРВОГО прогона без единого нового LLM-вызова.
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
        {--dry-run : не делать реальных LLM-вызовов — только зафиксировать пустой прогон}
        {--split=all : подвыборка датасета tune|holdout|all — членство в holdout авторитетно (D120)}
        {--write-splits : пересобрать estimates/eval-splits.json по правилу отбора (D119) и выйти, без прогона}';

    protected $description = 'Прогон extraction-пайплайна по golden-датасету, метрики precision/recall/F1 + row coverage';

    public function __construct(
        private readonly AnalysisStoreRepository $analyses,
        private readonly AnalysisRunStoreRepository $runs,
        private readonly AiRouteResolver $routes,
        private readonly ExtractionOrchestrator $orchestrator,
        private readonly EvalMetrics $metrics,
        private readonly ScreeningProcessor $screeningProcessor,
        private readonly ScreeningEvalMetrics $screeningMetrics,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('write-splits')) {
            return $this->writeSplits();
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

        $query = DatasetExample::query()->where('task', $task)->with('documentVersion');

        if ($split !== 'all') {
            $holdoutCodes = $this->loadHoldoutCodes();

            $query->whereHas('fixture', function ($fixtureQuery) use ($holdoutCodes, $split): void {
                $split === 'holdout'
                    ? $fixtureQuery->whereIn('code', $holdoutCodes)
                    : $fixtureQuery->whereNotIn('code', $holdoutCodes);
            });
        }

        if ($this->option('limit') !== null) {
            $query->limit((int) $this->option('limit'));
        }

        $examples = $query->get();

        if ($this->option('dry-run') || $examples->isEmpty()) {
            $this->info($examples->isEmpty()
                ? 'Датасет пуст — dry-run по факту (ни одного LLM-вызова).'
                : 'Dry-run: реальные LLM-вызовы пропущены по флагу --dry-run.');

            $run = EvalRun::query()->create([
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

        return $task === 'screening'
            ? $this->runScreening($examples, $datasetVersion)
            : $this->runExtraction($examples, $datasetVersion);
    }

    /**
     * @param  Collection<int, DatasetExample>  $examples
     */
    private function runExtraction(Collection $examples, string $datasetVersion): int
    {
        $route = $this->routes->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows);

        $run = EvalRun::query()->create([
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

        foreach ($examples as $example) {
            $analysisId = null;
            $metrics = $this->evaluateExample($example, $analysisId);
            $rows[] = ['example' => $example, 'metrics' => $metrics];

            if ($analysisId !== null) {
                $analysisIds[] = $analysisId;
            }

            EvalResult::query()->create([
                'eval_run_id' => $run->id,
                'dataset_example_id' => $example->id,
                'metric' => 'f1',
                'value' => $metrics['overall']['f1'] ?? 0.0,
                'details' => $metrics,
            ]);
        }

        $aggregate = $this->aggregate(collect($rows));
        $run->update([
            'metrics' => $aggregate,
            'finished_at' => now(),
            'prompt_version_set' => $this->resolvePromptVersionSet($analysisIds, $run->started_at),
        ]);

        $this->renderReport($run, $aggregate, collect($rows));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, DatasetExample>  $examples
     */
    private function runScreening(Collection $examples, string $datasetVersion): int
    {
        $route = $this->routes->resolve(DocumentTypeScope::Any, CoreLlmPurpose::Screening);

        $run = EvalRun::query()->create([
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

        foreach ($examples as $example) {
            $analysisId = null;
            $comparison = $this->evaluateScreeningExample($example, $analysisId);
            $comparisons[] = ['example' => $example, 'comparison' => $comparison];

            if ($analysisId !== null) {
                $analysisIds[] = $analysisId;
            }

            EvalResult::query()->create([
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
        $run->update([
            'metrics' => $aggregate,
            'finished_at' => now(),
            'prompt_version_set' => $this->resolvePromptVersionSet($analysisIds, $run->started_at),
        ]);

        $this->renderScreeningReport($run, $aggregate, collect($comparisons));

        return self::SUCCESS;
    }

    /**
     * @param  int|null  $analysisId  by-ref: id обработанного `Analysis` (для сборки
     *                                `prompt_version_set` прогона) — остаётся `null`, если пример
     *                                упал раньше создания `Analysis`.
     * @return array{overall: array{precision: float, recall: float, f1: float}, row_coverage: float}|array{error: string}
     */
    private function evaluateExample(DatasetExample $example, ?int &$analysisId = null): array
    {
        $documentVersion = $example->documentVersion;

        if ($documentVersion === null) {
            return ['error' => 'document_version отсутствует'];
        }

        // Launch-точка (§3.5 root/document-contour.md) — КАЖДЫЙ пример это отдельный прогон
        // (mode=manual, ручной harness); `Analysis` ниже переиспользуется той же строкой между
        // прогонами `ai:eval:run` (partial unique), `analysis_run_id` переставляется явно при
        // сбросе — тот же приём, что `FormatBenchRunner::runCell()`.
        $run = $this->runs->start($documentVersion->id, RunMode::Manual);

        try {
            $document = Document::query()->findOrFail($documentVersion->document_id);
            $analysis = $this->analyses->firstOrCreate($documentVersion->id, AnalysisStep::Extraction);
            $analysisId = $analysis->id;

            // Уникальность `(document_version_id, step)` (миграция `2026_07_04_100025`) не
            // допускает второй строки на повторный прогон — `firstOrCreate()` выше возвращает
            // ТУ ЖЕ Analysis, что и на предыдущем `ai:eval:run`. Без явного сброса
            // `ExtractionOrchestrator::buildSession()` резюмирует persisted `progress` прошлого
            // прогона: все сметы уже `rows_done=true`/`totals_done=true`, `firstUnfinishedIndex()`
            // сразу `null` → цикл завершается БЕЗ единого нового LLM-вызова, повторно отдавая
            // СТАРЫЙ результат (HIGH, ревью P7.4) — владелец правит промты/модель, перезапускает
            // eval и молча получает нулевое изменение метрик. Сброс до состояния «свежий анализ»
            // делает каждый прогон полноценным пересчётом, как того требует Intent item'а
            // (объективная обратная связь для итеративной настройки промтов).
            $this->analyses->update($analysis, [
                'status' => AnalysisStatus::Pending,
                'analysis_run_id' => $run->id,
                'document_type' => AiDocumentType::Estimate->value,
                'result' => null,
                'progress' => null,
                'provider' => null,
                'model' => null,
                'attempts' => 0,
                'last_error' => null,
                'last_error_at' => null,
                'next_retry_at' => null,
                'failed_permanently' => false,
                'started_at' => null,
                'finished_at' => null,
                'cache_id' => null,
                'cache_expires_at' => null,
            ]);
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
            $this->error("dataset_example #{$example->id}: {$e->getMessage()}");
            $this->runs->markFailed($run);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param  int|null  $analysisId  by-ref, см. {@see evaluateExample()}.
     * @return array{gold: string, predicted: string, match: bool, gold_is_estimate: bool,
     *     predicted_is_estimate: bool, code: string|null, confidence: float, ignore_reason: string|null}|array{error: string}
     */
    private function evaluateScreeningExample(DatasetExample $example, ?int &$analysisId = null): array
    {
        $documentVersion = $example->documentVersion;

        if ($documentVersion === null) {
            return ['error' => 'document_version отсутствует'];
        }

        // Launch-точка (§3.5 root/document-contour.md) — mode=manual; в отличие от extraction
        // (`evaluateExample()`), автохук `AnalysisStoreRepository::maybeTerminateRun()` НЕ
        // терминирует прогон на шаге `Screening` (ветвление принадлежит `ScreeningOutcomeHandler`,
        // которого этот standalone-eval не вызывает) — терминация здесь ЯВНАЯ.
        $run = $this->runs->start($documentVersion->id, RunMode::Manual);

        try {
            $document = Document::query()->findOrFail($documentVersion->document_id);
            $analysis = $this->analyses->firstOrCreate($documentVersion->id, AnalysisStep::Screening);
            $analysisId = $analysis->id;

            // Тот же приём, что `evaluateExample()` (extraction): без сброса `firstOrCreate()`
            // вернул бы терминальный `Analysis` прошлого прогона, и повторный `ai:eval:run`
            // отдавал бы старый вердикт без единого нового вызова `ScreeningProcessor`.
            $this->analyses->update($analysis, [
                'status' => AnalysisStatus::Pending,
                'analysis_run_id' => $run->id,
                'document_type' => null,
                'result' => null,
                'provider' => null,
                'model' => null,
                'attempts' => 0,
                'last_error' => null,
                'last_error_at' => null,
                'next_retry_at' => null,
                'failed_permanently' => false,
                'started_at' => null,
                'finished_at' => null,
            ]);
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
            $this->error("dataset_example #{$example->id}: {$e->getMessage()}");
            $this->runs->markFailed($run);

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

    /**
     * @param  array{total: int, accuracy: float|null, confusion: array<string, array<string, int>>,
     *     false_positive_rate: float|null, false_negative_rate: float|null}  $aggregate
     * @param  Collection<int, array{example: DatasetExample, comparison: array<string, mixed>}>  $rows
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

    /**
     * Пересобирает `estimates/eval-splits.json` по правилу отбора D119 (детерминированное,
     * `research/18-holdout-split-rule.md` §6): пул — `gold_source=deterministic`, группировка по
     * `form` (возрастание), группа из одного эталона остаётся в `tune` целиком, в группе ≥2 берётся
     * ПЕРВЫЙ по возрастанию кода кандидат, чей уход в `holdout` не оставляет `tune` без единого
     * эталона его `source_format`. `tune` в файле — снимок дополнения пула на момент генерации
     * (D120), не определение фильтра.
     */
    private function writeSplits(): int
    {
        // Пять профильных полей (§6.2 document-type-axis.md) живут в jsonb `type_profile` без
        // индексов (§6.4) — предикат/сортировка/выборка `form`/`gold_source` идут через jsonb-путь
        // (Implementation Rules P1.4 п. 11), не через top-level колонку.
        $pool = Fixture::query()
            ->where('type_profile->gold_source', GoldSource::Deterministic->value)
            ->orderByRaw("type_profile->>'form'")
            ->orderBy('code')
            ->get(['code', 'source_format', DB::raw("type_profile->>'form' as form")]);

        if ($pool->isEmpty()) {
            $this->error('Пул детерминированного gold пуст (prc_ai_fixtures.gold_source=deterministic) — нечего разбивать.');

            return self::FAILURE;
        }

        $holdout = [];

        foreach ($pool->groupBy('form') as $group) {
            if ($group->count() < 2) {
                continue;
            }

            foreach ($group->sortBy('code') as $candidate) {
                $remaining = $pool->reject(
                    fn (Fixture $fixture): bool => in_array($fixture->code, $holdout, true) || $fixture->code === $candidate->code,
                );

                if ($remaining->contains(fn (Fixture $fixture): bool => $fixture->source_format === $candidate->source_format)) {
                    $holdout[] = $candidate->code;

                    break;
                }
            }
        }

        $tune = $pool->pluck('code')
            ->reject(fn (string $code): bool => in_array($code, $holdout, true))
            ->values()
            ->all();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'rule' => 'holdout: form-stratified (D119) — группы по form (возрастание), в группе ≥2 '
                .'первый по коду кандидат, чей уход не оставляет tune без эталона его source_format; '
                .'форма с единственным эталоном остаётся в tune целиком. tune — дополнение пула, не '
                .'определение фильтра (D120).',
            'holdout' => $holdout,
            'tune' => $tune,
        ];

        file_put_contents(
            (string) config('ai.fixtures.splits_path'),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->info('estimates/eval-splits.json переписан: holdout=['.implode(', ', $holdout).'] tune=['.implode(', ', $tune).']');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function loadHoldoutCodes(): array
    {
        $path = (string) config('ai.fixtures.splits_path');

        if (! is_file($path)) {
            throw new RuntimeException("Файл разбиения не найден: {$path}. Прогони `ai:eval:run --write-splits`.");
        }

        $decoded = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        return array_values((array) ($decoded['holdout'] ?? []));
    }

    /**
     * Версии промтов, реально участвовавших в вызовах ЭТОГО прогона (`llm_calls`,
     * отфильтрованные по `analysis_id` из обработанных примеров И `called_at >= $since` — тот же
     * `Analysis` мог обслужить предыдущий `ai:eval:run`, старые вызовы не в счёт). Расхождение
     * версии одного ключа МЕЖДУ вызовами прогона делает прогон непригодным для сравнения —
     * `null` здесь читается `ai:eval:compare` как отказ, неотличимый от «вызовов не было» (оба
     * исхода одинаково непригодны для критерия приёмки).
     *
     * @param  list<int>  $analysisIds
     * @return array<string, int>|null
     */
    private function resolvePromptVersionSet(array $analysisIds, Carbon $since): ?array
    {
        if ($analysisIds === []) {
            return null;
        }

        $calls = LlmCall::query()
            ->whereIn('analysis_id', $analysisIds)
            ->where('called_at', '>=', $since)
            ->get();

        if ($calls->isEmpty()) {
            return null;
        }

        $merged = [];

        foreach ($calls as $call) {
            foreach ($call->prompt_version_set?->all() ?? [] as $key => $version) {
                if (array_key_exists($key, $merged) && $merged[$key] !== $version) {
                    return null;
                }

                $merged[$key] = $version;
            }
        }

        return $merged;
    }
}
