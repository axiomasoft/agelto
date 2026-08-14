<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\CorePromptStep;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Services\Llm\Contracts\SupportsFileUpload;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\LlmCallRecorder;
use App\Services\Llm\LlmRouteResolver;
use App\Services\Llm\PromptComposer;
use App\Services\Llm\Schema\JsonSchemaGenerator;
use App\Services\Llm\Validation\StructuredResponseValidator;
use App\Services\Procurement\Ai\UsageSnapshotRecorder;
use App\Services\Procurement\Prepare\RepresentationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Скрининг (первичный анализ, `docs/specs/v2/03-document-pipeline.md` §3.4, D6): вырезает первые
 * N страниц PDF (`PdfPageSlicer`, D28) — изображения идут целиком (vision-путь); прочие форматы,
 * дошедшие досюда, — `unprocessable(unreadable_format)` (D32). Одиночный вызов
 * `completeStructured()` на основном маршруте `(any, screening)` + `StructuredResponseValidator`
 * (P3.4) — БЕЗ `ReaskPolicy`: та возвращает только `ValidationOutcome` без usage/response,
 * а `LlmCallRecorder` (Scope Included) обязан писать usage реального вызова; повтор при
 * MAX_TOKENS/провале валидации — на уровне job-retry (backoff `HasErrorBlock`), не
 * внутришаговый re-ask (Known Deviations P5.1).
 */
final readonly class ScreeningProcessor
{
    public function __construct(
        private MarkDocumentUnprocessableAction $markDocumentUnprocessable,
        private AnalysisStoreRepository $analyses,
        private LlmRouteResolver $routes,
        private PromptComposer $prompts,
        private JsonSchemaGenerator $schemas,
        private StructuredResponseValidator $validator,
        private LlmCallRecorder $recorder,
        private PdfPageSlicer $slicer,
        private RepresentationResolver $representations,
        private UsageSnapshotRecorder $usageSnapshot,
    ) {}

    /**
     * `$routeIndex` — позиция в `LlmRouteResolver::candidatesFor()` (priority ASC): 0 — основной
     * маршрут; `ScreeningOutcomeHandler` (P5.2, D36) передаёт 1 для одноразовой
     * borderline-эскалации на маршрут классом выше (тот же документ, тот же `Analysis`,
     * повторная резка/загрузка — временные файлы предыдущего прогона уже удалены в `finally`).
     */
    public function run(Document $document, Analysis $analysis, int $routeIndex = 0): ?ScreeningResult
    {
        $this->analyses->update($analysis, [
            'status' => AnalysisStatus::Running,
            'started_at' => now(),
        ]);

        $version = $analysis->documentVersion;

        if (! $version instanceof DocumentVersion) {
            $this->markUnprocessable(
                $document,
                $analysis,
                UnprocessableReason::UnreadableFormat,
                'у analysis нет версии документа для скрининга',
            );

            return null;
        }

        $effective = $this->resolveEffectiveFile($version);

        if ($effective === null) {
            $this->markUnprocessable(
                $document,
                $analysis,
                UnprocessableReason::UnreadableFormat,
                "формат «{$version->extension}» не поддержан скринингом",
            );

            return null;
        }

        $disk = Storage::disk($effective['disk']);
        $localPath = tempnam(sys_get_temp_dir(), 'screening_').'.'.$effective['extension'];
        file_put_contents($localPath, $disk->get($effective['storagePath']));

        $slicedPath = null;

        try {
            $uploadPath = $localPath;

            if ($effective['extension'] === 'pdf') {
                try {
                    $slicedPath = $this->slicer->slice($localPath, $this->positiveIntConfig(key: 'ai.screening.pages', default: 4));
                    $uploadPath = $slicedPath;
                } catch (RuntimeException $exception) {
                    $this->markUnprocessable(
                        $document,
                        $analysis,
                        $this->classifyPdfError($exception->getMessage()),
                        $exception->getMessage(),
                    );

                    return null;
                }
            }

            return $this->classify($analysis, $uploadPath, $effective['mime'], $routeIndex);
        } finally {
            if ($slicedPath !== null && $slicedPath !== $localPath) {
                @unlink($slicedPath);
            }

            @unlink($localPath);
        }
    }

    /**
     * Какое представление версии уходит в LLM, решает {@see RepresentationResolver} (D25, P3.1);
     * здесь остаётся ТОЛЬКО правило скрининга «что мы вообще умеем отправить»: PDF и изображения.
     *
     * @return array{storagePath: string, disk: string, extension: string, mime: string}|null
     */
    private function resolveEffectiveFile(DocumentVersion $version): ?array
    {
        $effective = $this->representations->forLlm($version);

        if ($effective !== null && ($effective['extension'] === 'pdf' || str_starts_with($effective['mime'], 'image/'))) {
            return $effective;
        }

        return null;
    }

    private function classify(Analysis $analysis, string $filePath, string $mimeType, int $routeIndex = 0): ScreeningResult
    {
        $primary = $this->routes->candidatesFor(DocumentTypeScope::Any, CoreLlmPurpose::Screening)->values()->get($routeIndex);

        if ($primary === null) {
            throw new RuntimeException("Нет активного маршрута для document_type=any, purpose=screening, индекс={$routeIndex}.");
        }

        if (! $primary->provider instanceof SupportsFileUpload) {
            throw new RuntimeException('Провайдер маршрута скрининга не поддерживает Files API.');
        }

        $composed = $this->prompts->compose(CorePromptStep::ScreeningClassify, [ScreeningResult::class]);
        $promptVersionSet = $this->stringPromptVersionSet($composed->promptVersionSet);
        $schema = new JsonSchema($this->schemas->generate(ScreeningResult::class));
        $fileRef = $primary->provider->uploadFile($filePath, $mimeType);

        $prompt = new LlmPrompt(
            systemPart: $composed->systemPart,
            userParts: ['Классифицируй документ по правилам.'],
            fileRefs: [$fileRef],
        );

        // maxTokens скрининга — конфигурируемый маленький потолок (Code Guidance P5.1), не
        // дефолт `LlmRouteResolver` (8192, рассчитан на extraction-шаги).
        $options = new LlmOptions(
            provider: $primary->options->provider,
            model: $primary->options->model,
            temperature: $primary->options->temperature,
            maxTokens: $this->positiveIntConfig(key: 'ai.screening.max_tokens', default: 1024),
            cacheHandle: $primary->options->cacheHandle,
        );

        $calledAt = CarbonImmutable::now();
        $startedAt = microtime(true);
        $requestPayload = json_encode(['systemPart' => $composed->systemPart, 'userParts' => $prompt->userParts], JSON_THROW_ON_ERROR);

        try {
            $response = $primary->provider->completeStructured($prompt, $schema, $options);
        } catch (Throwable $exception) {
            $this->recorder->record(
                analysisId: $analysis->id,
                purpose: CoreLlmPurpose::Screening,
                provider: $options->provider,
                model: $options->model,
                promptVersionSet: $promptVersionSet,
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

        $outcome = $this->validator->validate($response, ScreeningResult::class);

        $this->recorder->record(
            analysisId: $analysis->id,
            purpose: CoreLlmPurpose::Screening,
            provider: $options->provider,
            model: $options->model,
            promptVersionSet: $promptVersionSet,
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
            throw new RuntimeException("Скрининг: ответ не прошёл валидацию — {$outcome->reason}");
        }

        /** @var ScreeningResult $result */
        $result = $outcome->data;

        $this->analyses->update($analysis, [
            'provider' => $options->provider,
            'model' => $options->model,
            'rules_hash' => $composed->rulesHash,
        ]);

        return $result;
    }

    private function classifyPdfError(string $message): UnprocessableReason
    {
        $normalized = mb_strtolower($message);

        return str_contains($normalized, 'password') || str_contains($normalized, 'encrypt')
            ? UnprocessableReason::PasswordProtected
            : UnprocessableReason::UnreadableFormat;
    }

    private function markUnprocessable(Document $document, Analysis $analysis, UnprocessableReason $reason, string $detail): void
    {
        $this->markDocumentUnprocessable->execute($document, $reason, $detail);

        $this->usageSnapshot->finalizeAnalysis($analysis, [
            'status' => AnalysisStatus::Skipped,
            'result' => ['unprocessable_reason' => $reason->value, 'detail' => $detail],
            'finished_at' => now(),
        ]);
    }

    private function positiveIntConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value <= 0) {
            throw new RuntimeException("{$key} must be a positive integer.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $versions
     * @return array<string, string>
     */
    private function stringPromptVersionSet(array $versions): array
    {
        $result = [];

        foreach ($versions as $key => $version) {
            if (! is_string($key) || ! is_int($version)) {
                throw new RuntimeException('Prompt version set must map string keys to integers.');
            }

            $result[$key] = (string) $version;
        }

        return $result;
    }
}
