<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Analyzers\Estimate\TotalsResponse;
use App\Enums\Llm\FinishReason;
use App\Enums\Llm\LlmPurpose;
use App\Enums\Llm\PromptStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Llm\CorrectionAwareComposer;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Dto\ResolvedRoute;
use App\Services\Llm\LlmCallRecorder;
use App\Services\Llm\PromptComposer;
use App\Services\Llm\Schema\JsonSchemaGenerator;
use App\Services\Llm\Validation\StructuredResponseValidator;
use App\Services\Procurement\Analyzers\Estimate\Dto\ChunkFetchResult;
use App\Services\Procurement\Extraction\Exceptions\MaxTokensExceededException;
use App\Services\Procurement\Extraction\ExtractionSession;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * Основной путь извлечения (D3/D40) — row-range по целому документу, кэшированному в Files API
 * (`ExtractionSession::fileHandle`). БЕЗ `ReaskPolicy` (тот же Known Deviation, что P5.1
 * `ScreeningProcessor`: `ReaskPolicy::run()` не отдаёт usage для `LlmCallRecorder`) —
 * `StructuredResponseValidator` напрямую + повтор/эскалация на уровне job-backoff
 * (`HasErrorBlock`), кроме MAX_TOKENS на `fetchRows()` — тот сигнализирует оркестратору
 * уменьшить окно (`MaxTokensExceededException`, Implementation Rules P6.1), а не просто падает.
 *
 * Explicit context cache (если создан, `ExtractionSession::cacheHandle`) заменяет пересылку
 * файла — при активном кэше `fileRefs` пустой (контент уже в `cachedContentName`); implicit-путь
 * (кэша нет) пересылает `fileHandle` на КАЖДЫЙ вызов, полагаясь на implicit-кэш провайдера на
 * стабильном префиксе (D8).
 */
final readonly class RowRangeChunkSource implements ChunkSource
{
    public function __construct(
        private PromptComposer $prompts,
        private JsonSchemaGenerator $schemas,
        private StructuredResponseValidator $validator,
        private LlmCallRecorder $recorder,
        private CorrectionAwareComposer $corrections,
        private EstimateStepDictionaries $dictionaries,
    ) {}

    public function fetchPassport(
        int $analysisId,
        ResolvedRoute $route,
        ExtractionSession $session,
        int $ordinal,
        array $knownEstimateNumbers,
    ): ChunkFetchResult {
        $known = $knownEstimateNumbers === [] ? 'ещё не найдено ни одной сметы' : 'уже найдены: '.implode(', ', $knownEstimateNumbers);
        $userPart = "Найди смету №{$ordinal} по порядку появления в документе ({$known}). Если в документе больше нет смет — верни estimate_number=null и rows_expected=0.";

        [$data, $usage] = $this->call(
            analysisId: $analysisId,
            purpose: EstimateLlmPurpose::ExtractPassport,
            step: EstimatePromptStep::ExtractionPassport,
            dataClass: PassportResponse::class,
            route: $route,
            session: $session,
            userPart: $userPart,
        );

        /** @var PassportResponse $data */
        $isTerminator = $data->estimate_number === null && $data->rows_expected === 0;

        return new ChunkFetchResult(data: $isTerminator ? null : $data, usage: $usage);
    }

    public function fetchRows(
        int $analysisId,
        ResolvedRoute $route,
        ExtractionSession $session,
        int $estimateOrdinal,
        int $rowStart,
        int $rowLength,
    ): ChunkFetchResult {
        $rowEnd = $rowStart + $rowLength - 1;
        $userPart = "Верни строки {$rowStart}–{$rowEnd} сметы №{$estimateOrdinal}. Нечитаемое/разорванное — в incomplete_items, не выдумывай. Строк в этом диапазоне больше нет — верни пустой rows.";

        [$data, $usage] = $this->call(
            analysisId: $analysisId,
            purpose: EstimateLlmPurpose::ExtractRows,
            step: EstimatePromptStep::ExtractionRows,
            dataClass: RowsChunkResponse::class,
            route: $route,
            session: $session,
            userPart: $userPart,
            onMaxTokens: static fn () => throw new MaxTokensExceededException(
                "extract_rows: MAX_TOKENS на диапазоне {$rowStart}-{$rowEnd} сметы №{$estimateOrdinal}.",
            ),
        );

        /** @var RowsChunkResponse $data */
        return new ChunkFetchResult(data: $data, usage: $usage);
    }

    public function fetchTotals(
        int $analysisId,
        ResolvedRoute $route,
        ExtractionSession $session,
        int $estimateOrdinal,
    ): ChunkFetchResult {
        $userPart = "Верни итоги (footer) сметы №{$estimateOrdinal}.";

        [$data, $usage] = $this->call(
            analysisId: $analysisId,
            purpose: EstimateLlmPurpose::ExtractTotals,
            step: EstimatePromptStep::ExtractionTotals,
            dataClass: TotalsResponse::class,
            route: $route,
            session: $session,
            userPart: $userPart,
        );

        /** @var TotalsResponse $data */
        return new ChunkFetchResult(data: $data, usage: $usage);
    }

    /**
     * @param  class-string<Data>  $dataClass
     * @return array{0: Data, 1: LlmUsage}
     */
    private function call(
        int $analysisId,
        LlmPurpose $purpose,
        PromptStep $step,
        string $dataClass,
        ResolvedRoute $route,
        ExtractionSession $session,
        string $userPart,
        ?Closure $onMaxTokens = null,
    ): array {
        $composed = $this->prompts->compose($step, [$dataClass, ...$this->dictionaries->forStep($step)]);
        $schema = new JsonSchema($this->schemas->generate($dataClass));
        $hint = $this->correctionsHint($analysisId);

        $fileRefs = $session->cacheHandle !== null || $session->fileHandle === null ? [] : [$session->fileHandle];

        $prompt = new LlmPrompt(
            systemPart: $composed->systemPart,
            userParts: $hint === null ? [$userPart] : [$hint, $userPart],
            fileRefs: $fileRefs,
        );

        $options = $session->cacheHandle === null ? $route->options : new LlmOptions(
            provider: $route->options->provider,
            model: $route->options->model,
            temperature: $route->options->temperature,
            maxTokens: $route->options->maxTokens,
            cacheHandle: $session->cacheHandle,
        );

        $calledAt = CarbonImmutable::now();
        $startedAt = microtime(true);
        $requestPayload = json_encode(['systemPart' => $composed->systemPart, 'userParts' => $prompt->userParts], JSON_THROW_ON_ERROR);

        try {
            $response = $route->provider->completeStructured($prompt, $schema, $options);
        } catch (Throwable $exception) {
            $this->recorder->record(
                analysisId: $analysisId,
                purpose: $purpose,
                provider: $options->provider,
                model: $options->model,
                promptVersionSet: $composed->promptVersionSet,
                cacheId: $options->cacheHandle?->id,
                requestPayload: $requestPayload,
                responsePayload: null,
                usage: null,
                success: false,
                error: $exception->getMessage(),
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                calledAt: $calledAt,
            );

            throw $exception;
        }

        if ($onMaxTokens !== null && $response->finishReason === FinishReason::MaxTokens) {
            $this->recorder->record(
                analysisId: $analysisId,
                purpose: $purpose,
                provider: $options->provider,
                model: $options->model,
                promptVersionSet: $composed->promptVersionSet,
                cacheId: $options->cacheHandle?->id,
                requestPayload: $requestPayload,
                responsePayload: $response->raw,
                usage: $response->usage,
                success: false,
                error: 'MAX_TOKENS',
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                calledAt: $calledAt,
            );

            $onMaxTokens();
        }

        $outcome = $this->validator->validate($response, $dataClass);

        $this->recorder->record(
            analysisId: $analysisId,
            purpose: $purpose,
            provider: $options->provider,
            model: $options->model,
            promptVersionSet: $composed->promptVersionSet,
            cacheId: $options->cacheHandle?->id,
            requestPayload: $requestPayload,
            responsePayload: $response->raw,
            usage: $response->usage,
            success: $outcome->isOk(),
            error: $outcome->isOk() ? null : $outcome->reason,
            durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            calledAt: $calledAt,
        );

        if (! $outcome->isOk()) {
            throw new RuntimeException("{$step->value}: ответ не прошёл валидацию — {$outcome->reason}");
        }

        if ($outcome->data === null) {
            throw new RuntimeException("{$step->value}: валидный исход не содержит Data.");
        }

        return [$outcome->data, $response->usage];
    }

    /**
     * `CorrectionAwareComposer` (P9.2) принимает модель `Analysis`, а этот класс работает по
     * `analysisId` (контракт `ChunkSource`) — единственный источник для загрузки нужной записи.
     * `null` — обычный (не re-run) прогон без коррекций, вызывающий код НЕ добавляет userPart.
     */
    private function correctionsHint(int $analysisId): ?string
    {
        $analysis = Analysis::query()->find($analysisId);

        return $analysis === null ? null : $this->corrections->correctionsBlock($analysis);
    }
}
