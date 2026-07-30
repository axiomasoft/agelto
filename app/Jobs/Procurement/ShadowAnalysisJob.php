<?php

declare(strict_types=1);

namespace App\Jobs\Procurement;

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStatus;
use App\Models\Procurement\Ai\Analysis;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\LlmCallRecorder;
use App\Services\Llm\LlmRouteResolver;
use App\Services\Procurement\Ai\UsageSnapshotRecorder;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Contracts\SupportsResultComparison;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Теневой прогон одного LLM-вызова против маршрута `shadow=true` (P9.4, ТЗ 04 §5) — дублирует
 * ровно ТОТ ЖЕ промт (система+user-части+схема), что ушёл на боевой провайдер (`ShadowDispatch`),
 * на кандидата (локальная LLM/другой провайдер). Результат — отдельный `Ai\Analysis(is_shadow=
 * true)`, LLM-вызов пишется в `llm_calls` с `purpose=shadow` (видно в `ai:cost-report`,
 * P7.2). НЕ наследует `PipelineJob` — не часть status-guard/lease жизненного цикла боевой
 * стадии (`Implementation Rules P9.4`: shadow не влияет на боевой путь), поэтому `handle()`
 * целиком в `try/catch` — исключение НИКОГДА не пробрасывается наружу (не создаёт Horizon-алерт
 * по боевому пайплайну; диагностика — только лог).
 *
 * Сравнение с основным анализом (§1.5 контракта P2.1, D106) — best-effort: маршрутизируется через
 * `DocumentAnalyzerRegistry` на capability-интерфейс `SupportsResultComparison` типа документа;
 * применимо только когда тип его реализует (сегодня — только смета, `EstimateAnalyzer`) и ОБЕ
 * стороны несут непустой payload. Для кросс-документных вызовов (`DocumentTypeScope`) и типов без
 * этой способности `comparison` в `result` теневого анализа остаётся `null`.
 *
 * KNOWN DEVIATION (P9.4, задокументировано, не молча): Code Guidance пункта предписывал писать
 * сравнение в `prc_ai_eval_results` — миграция `2026_07_04_100029` требует НЕ NULL
 * `dataset_example_id` (FK на голден-датасет) и не несёт связи с `Ai\Analysis` вовсе; теневой
 * прогон происходит на ПРОИЗВОЛЬНОМ боевом документе, не обязательно входящем в golden dataset,
 * поэтому эта таблица структурно не может принять произвольную shadow-пару без новой миграции
 * (вне Scope/Files этого item'а — новая колонка/nullable-FK требует отдельного решения
 * plan-design). Сравнение вместо этого хранится в `result.comparison` самого теневого
 * `Ai\Analysis` (существующее jsonb-поле, без схемы) — Pending Work: перенести в
 * `prc_ai_eval_results` при появлении отдельной миграции.
 */
final class ShadowAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * `schema=null` — нетипизированный вызов (`complete()`), иначе `completeStructured()` по ней.
     *
     * @param  list<string>  $userParts
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(
        public readonly int $primaryAnalysisId,
        public readonly string $purpose,
        public readonly AiDocumentType|DocumentTypeScope $documentType,
        public readonly string $systemPart,
        public readonly array $userParts,
        public readonly ?array $schema,
    ) {
        $this->onQueue('ai_shadow');
    }

    public function handle(
        LlmRouteResolver $routes,
        AnalysisStoreRepository $analyses,
        LlmCallRecorder $recorder,
        DocumentAnalyzerRegistry $registry,
        UsageSnapshotRecorder $usageSnapshot,
    ): void {
        try {
            $this->run($routes, $analyses, $recorder, $registry, $usageSnapshot);
        } catch (Throwable $exception) {
            Log::warning('Shadow-анализ завершился ошибкой — боевой путь не затронут', [
                'primary_analysis_id' => $this->primaryAnalysisId,
                'purpose' => $this->purpose,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function run(
        LlmRouteResolver $routes,
        AnalysisStoreRepository $analyses,
        LlmCallRecorder $recorder,
        DocumentAnalyzerRegistry $registry,
        UsageSnapshotRecorder $usageSnapshot,
    ): void {
        $purpose = $registry->purposeFor($this->documentType, $this->purpose);
        $primary = Analysis::query()->find($this->primaryAnalysisId);

        if ($primary === null) {
            return;
        }

        // Маршрут мог деактивироваться/сняться с shadow между диспатчем и выполнением job'а —
        // no-op, а не ошибка (обычный race для очереди низкого приоритета).
        $route = $routes->shadowRoute($this->documentType, $purpose);

        if ($route === null) {
            return;
        }

        $shadow = $analyses->createShadow($primary);

        $prompt = new LlmPrompt(systemPart: $this->systemPart, userParts: $this->userParts);
        $schema = $this->schema !== null ? new JsonSchema($this->schema) : null;

        $calledAt = CarbonImmutable::now();
        $startedAt = microtime(true);
        $requestPayload = json_encode(['systemPart' => $this->systemPart, 'userParts' => $this->userParts], JSON_THROW_ON_ERROR);

        try {
            $response = $schema !== null
                ? $route->provider->completeStructured($prompt, $schema, $route->options)
                : $route->provider->complete($prompt, $route->options);
        } catch (Throwable $exception) {
            $recorder->record(
                analysisId: $shadow->id,
                purpose: CoreLlmPurpose::Shadow,
                provider: $route->options->provider,
                model: $route->options->model,
                promptVersionSet: [],
                cacheId: null,
                requestPayload: $requestPayload,
                responsePayload: null,
                usage: null,
                success: false,
                error: $exception->getMessage(),
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                calledAt: $calledAt,
            );

            $usageSnapshot->finalizeAnalysis($shadow, [
                'status' => AnalysisStatus::Failed,
                'last_error' => $exception->getMessage(),
                'last_error_at' => now(),
                'finished_at' => now(),
            ]);

            return;
        }

        $data = $response instanceof LlmStructuredResponse ? $response->data : ['text' => $response->data];

        $recorder->record(
            analysisId: $shadow->id,
            purpose: CoreLlmPurpose::Shadow,
            provider: $route->options->provider,
            model: $route->options->model,
            promptVersionSet: [],
            cacheId: null,
            requestPayload: $requestPayload,
            responsePayload: $response->raw,
            usage: $response->usage,
            success: true,
            error: null,
            durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            calledAt: $calledAt,
        );

        $usageSnapshot->finalizeAnalysis($shadow, [
            'status' => AnalysisStatus::Succeeded,
            'provider' => $route->options->provider,
            'model' => $route->options->model,
            'result' => [
                'output' => $data,
                'comparison' => $this->compareWithPrimary($registry, $primary, $data),
            ],
            'finished_at' => now(),
        ]);
    }

    /**
     * Явные ветки `null` (Implementation Rules 6–7, P2.7): кросс-документный вызов
     * (`DocumentTypeScope`) сравнения не имеет по построению, а отсутствие
     * `SupportsResultComparison` у анализатора — штатная опциональность, не ошибка.
     *
     * @param  array<string, mixed>  $shadowData
     * @return array<string, mixed>|null
     */
    private function compareWithPrimary(DocumentAnalyzerRegistry $registry, Analysis $primary, array $shadowData): ?array
    {
        if (! $this->documentType instanceof AiDocumentType) {
            return null;
        }

        $analyzer = $registry->for($this->documentType);

        if (! $analyzer instanceof SupportsResultComparison) {
            return null;
        }

        return $analyzer->compareResults(primaryResult: $primary->result?->all() ?? [], shadowResult: $shadowData);
    }
}
