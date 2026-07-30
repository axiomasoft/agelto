<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Ai\Estimate\DiscoveryResponse;
use App\Dto\Procurement\Ai\Estimate\PassportResponse;
use App\Dto\Procurement\Ai\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Ai\Estimate\TotalsResponse;
use App\Enums\Llm\LlmPurpose;
use App\Enums\Llm\PromptStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Services\Llm\Contracts\SupportsFileUpload;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Dto\ResolvedRoute;
use App\Services\Llm\LlmCallRecorder;
use App\Services\Llm\PromptComposer;
use App\Services\Llm\Schema\JsonSchemaGenerator;
use App\Services\Llm\Validation\StructuredResponseValidator;
use App\Services\Procurement\Analyzers\Estimate\Dto\ChunkFetchResult;
use App\Services\Procurement\Screening\PdfPageSlicer;
use Carbon\CarbonImmutable;
use RuntimeException;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * Источник чанков ОКОННОГО пути извлечения (D30/D42, `docs/specs/v2/08-estimate-extraction.md`
 * §4.2). Самостоятельный контракт — НЕ реализует `ChunkSource` (разрешение эскалации P6.4,
 * `phases/P6.md`): адресация страничная (`pageStart`/`pageEnd`), не row-index, и discovery-стадия
 * не имеет аналога в row-range пути. БЕЗ `ExtractionSession::fileHandle`/`cacheHandle` — каждый
 * вызов грузит СВОЙ временный PDF-срез (`PdfPageSlicer::sliceRange()` на `$localPath` документа,
 * тот же путь, что уже открыт в `ExtractionOrchestrator::run()`) через свежий `uploadFile()`, БЕЗ
 * переиспользования handle между вызовами (срезы маленькие — reupload дешевле сложности
 * TTL-инвалидации per-window кэша, Implementation Rules P6.4). Срез — временный локальный файл,
 * удаляется в `finally` сразу после вызова.
 *
 * Offset-инструкция вместо текстовых `[PAGE_START]`-маркеров (Implementation Rules P6.4,
 * расхождение с буквальным текстом спеки §4.2 «маркеры страниц», не с её намерением — v2 шлёт
 * модели сам PDF-файл среза, не извлечённый текст): `sliceRange($path, $a, $b)` физически
 * перенумеровывает страницы среза `1..($b-$a+1)`, модель этого не знает — user-часть КАЖДОГО
 * вызова явно объявляет смещение и требует ГЛОБАЛЬНЫЙ номер страницы исходного документа в
 * `page`-полях ответа.
 */
final readonly class WindowedChunkSource
{
    public function __construct(
        private PdfPageSlicer $slicer,
        private PromptComposer $prompts,
        private JsonSchemaGenerator $schemas,
        private StructuredResponseValidator $validator,
        private LlmCallRecorder $recorder,
    ) {}

    /**
     * Discovery-окно страниц `[$pageStart, $pageEnd]` (§3.4/§4.2 Стадия 1). `data` никогда не
     * `null` — пустой `markers=[]` валиден («в этом окне нет границ смет»).
     */
    public function fetchDiscoveryWindow(
        int $analysisId,
        ResolvedRoute $discoveryRoute,
        string $localPath,
        int $windowIndex,
        int $pageStart,
        int $pageEnd,
    ): ChunkFetchResult {
        $userPart = $this->offsetInstruction($pageStart, $pageEnd)
            ." Это discovery-окно №{$windowIndex}. Найди маркеры границ смет "
            .'(estimate_start/continuation/estimate_end) на видимых страницах — ты не видишь '
            .'документ целиком, размечай только то, что видно в этом окне.';

        [$data, $usage] = $this->call(
            analysisId: $analysisId,
            purpose: EstimateLlmPurpose::Discovery,
            step: EstimatePromptStep::ExtractionDiscovery,
            dataClass: DiscoveryResponse::class,
            route: $discoveryRoute,
            localPath: $localPath,
            pageStart: $pageStart,
            pageEnd: $pageEnd,
            userPart: $userPart,
        );

        /** @var DiscoveryResponse $data */
        return new ChunkFetchResult(data: $data, usage: $usage);
    }

    /**
     * Паспорт сегмента `$segmentOrdinal` (страницы `[$pageStart, $pageEnd]` сегмента — не всего
     * документа). `$titleHint` — заголовок из discovery-маркера `estimate_start`, если найден
     * (подсказка, не факт).
     */
    public function fetchSegmentPassport(
        int $analysisId,
        ResolvedRoute $route,
        string $localPath,
        int $segmentOrdinal,
        int $pageStart,
        int $pageEnd,
        ?string $titleHint,
    ): ChunkFetchResult {
        $hint = $titleHint !== null ? " Подсказка из предварительной разметки (не факт, уточни по документу): «{$titleHint}»." : '';

        $userPart = $this->offsetInstruction($pageStart, $pageEnd)
            ." Это сегмент №{$segmentOrdinal} (одна смета). Найди паспорт этой сметы — "
            .'rows_expected здесь оценка, не строгий гейт полноты (документ виден только частично).'
            .$hint;

        [$data, $usage] = $this->call(
            analysisId: $analysisId,
            purpose: EstimateLlmPurpose::ExtractPassport,
            step: EstimatePromptStep::ExtractionPassport,
            dataClass: PassportResponse::class,
            route: $route,
            localPath: $localPath,
            pageStart: $pageStart,
            pageEnd: $pageEnd,
            userPart: $userPart,
        );

        /** @var PassportResponse $data */
        return new ChunkFetchResult(data: $data, usage: $usage);
    }

    /**
     * Строки сегмента `$segmentOrdinal` в окне страниц `[$pageStart, $pageEnd]` (внутри границ
     * сегмента, не всего документа). БЕЗ `onMaxTokens`-колбэка row-range — страничное окно уже
     * минимально по построению, MAX_TOKENS здесь — обычная ошибка (job-retry), не сигнал
     * уменьшить окно (Implementation Rules P6.4).
     */
    public function fetchSegmentRows(
        int $analysisId,
        ResolvedRoute $route,
        string $localPath,
        int $segmentOrdinal,
        int $pageStart,
        int $pageEnd,
    ): ChunkFetchResult {
        $userPart = $this->offsetInstruction($pageStart, $pageEnd)
            ." Это сегмент №{$segmentOrdinal}. Верни строки, видимые на этих страницах. "
            .'Нечитаемое/разорванное на границе страницы — в incomplete_items, не выдумывай. '
            .'Строк на этих страницах больше нет — верни пустой rows.';

        [$data, $usage] = $this->call(
            analysisId: $analysisId,
            purpose: EstimateLlmPurpose::ExtractRows,
            step: EstimatePromptStep::ExtractionRows,
            dataClass: RowsChunkResponse::class,
            route: $route,
            localPath: $localPath,
            pageStart: $pageStart,
            pageEnd: $pageEnd,
            userPart: $userPart,
        );

        /** @var RowsChunkResponse $data */
        return new ChunkFetchResult(data: $data, usage: $usage);
    }

    /**
     * Итоги сегмента `$segmentOrdinal` по хвосту `[$pageStart, $pageEnd]` (§4.2 Стадия 3) —
     * зеркалит `ChunkSource::fetchTotals()` row-range пути, но по срезу хвоста сегмента.
     */
    public function fetchSegmentTotals(
        int $analysisId,
        ResolvedRoute $route,
        string $localPath,
        int $segmentOrdinal,
        int $pageStart,
        int $pageEnd,
    ): ChunkFetchResult {
        $userPart = $this->offsetInstruction($pageStart, $pageEnd)
            ." Это хвост сегмента №{$segmentOrdinal}. Верни итоги (footer) этой сметы.";

        [$data, $usage] = $this->call(
            analysisId: $analysisId,
            purpose: EstimateLlmPurpose::ExtractTotals,
            step: EstimatePromptStep::ExtractionTotals,
            dataClass: TotalsResponse::class,
            route: $route,
            localPath: $localPath,
            pageStart: $pageStart,
            pageEnd: $pageEnd,
            userPart: $userPart,
        );

        /** @var TotalsResponse $data */
        return new ChunkFetchResult(data: $data, usage: $usage);
    }

    private function offsetInstruction(int $pageStart, int $pageEnd): string
    {
        return "Это страницы {$pageStart}–{$pageEnd} исходного документа. В page-полях ответа "
            ."указывай ГЛОБАЛЬНЫЙ номер страницы исходного документа ({$pageStart} = первая "
            .'страница этого среза), НЕ номер страницы внутри среза.';
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
        string $localPath,
        int $pageStart,
        int $pageEnd,
        string $userPart,
    ): array {
        if (! $route->provider instanceof SupportsFileUpload) {
            throw new RuntimeException('Провайдер оконного маршрута извлечения не поддерживает Files API.');
        }

        $slicePath = $this->slicer->sliceRange($localPath, $pageStart, $pageEnd);

        try {
            $fileHandle = $route->provider->uploadFile($slicePath, 'application/pdf');

            $composed = $this->prompts->compose($step, [$dataClass]);
            $schema = new JsonSchema($this->schemas->generate($dataClass));

            $prompt = new LlmPrompt(
                systemPart: $composed->systemPart,
                userParts: [$userPart],
                fileRefs: [$fileHandle],
            );

            $calledAt = CarbonImmutable::now();
            $startedAt = microtime(true);
            $requestPayload = json_encode(['systemPart' => $composed->systemPart, 'userParts' => $prompt->userParts], JSON_THROW_ON_ERROR);

            try {
                $response = $route->provider->completeStructured($prompt, $schema, $route->options);
            } catch (Throwable $exception) {
                $this->recorder->record(
                    analysisId: $analysisId,
                    purpose: $purpose,
                    provider: $route->options->provider,
                    model: $route->options->model,
                    promptVersionSet: $composed->promptVersionSet,
                    cacheId: null,
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

            $outcome = $this->validator->validate($response, $dataClass);

            $this->recorder->record(
                analysisId: $analysisId,
                purpose: $purpose,
                provider: $route->options->provider,
                model: $route->options->model,
                promptVersionSet: $composed->promptVersionSet,
                cacheId: null,
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

            return [$outcome->data, $response->usage];
        } finally {
            @unlink($slicePath);
        }
    }
}
