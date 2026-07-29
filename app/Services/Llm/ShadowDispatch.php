<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\Procurement\LlmPurpose;
use App\Jobs\Procurement\ShadowAnalysisJob;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmPrompt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Перехват основного LLM-вызова для shadow-режима (P9.4, ТЗ 04 §5): если для
 * `(document_type, purpose)` заведён активный `llm_routes.shadow=true` маршрут, дублирует
 * вызов на очередь низкого приоритета (`ShadowAnalysisJob`) — сравнение качества/стоимости с
 * основным провайдером ДО переключения. Вызывается СРАЗУ ПОСЛЕ успешного основного вызова
 * (`ScreeningProcessor`/extraction-цепочка), уже имеющего под рукой `LlmPrompt`/`JsonSchema` этого
 * конкретного вызова — сам `ShadowDispatch` их не пересобирает.
 *
 * `Implementation Rules P9.4`: shadow НИКОГДА не влияет на боевой путь — единственный способ
 * узнать, что shadow не запустился, это лог (`Log::warning`), исключение отсюда наружу не
 * выходит НИ ПРИ КАКИХ обстоятельствах (в т.ч. если резолв теневого маршрута сам бросил).
 *
 * ОГРАНИЧЕНИЕ (согласовано с докблоком `OpenAiCompatibleProvider`): вызовы с непустым
 * `LlmPrompt::$fileRefs` (Files API, `ScreeningProcessor`) НЕ дублируются — OpenAI-совместимый
 * адаптер не поддерживает Files API, инлайновая передача файла (vision-сообщение) — вне Scope
 * этого item'а (Pending Work P9.4: инлайн-кодирование скана/PDF для теневого скрининга).
 */
final readonly class ShadowDispatch
{
    public function __construct(
        private AiRouteResolver $routes,
    ) {}

    public function afterCall(
        Analysis $analysis,
        LlmPurpose $purpose,
        string $documentType,
        LlmPrompt $prompt,
        ?JsonSchema $schema,
    ): void {
        if ($prompt->fileRefs !== []) {
            return;
        }

        try {
            $shadowRoute = $this->routes->shadowRoute($documentType, $purpose);

            if ($shadowRoute === null) {
                return;
            }

            // Очередь низкого приоритета — уже задана конструктором `ShadowAnalysisJob`
            // (`onQueue('ai_shadow')`), тот же приём, что `ScreeningJob`/`EstimateExtractionJob`.
            ShadowAnalysisJob::dispatch(
                primaryAnalysisId: $analysis->id,
                purpose: $purpose->value,
                documentType: $documentType,
                systemPart: $prompt->systemPart,
                userParts: $prompt->userParts,
                schema: $schema?->schema,
            );
        } catch (Throwable $exception) {
            Log::warning('Shadow-диспатч пропущен из-за ошибки резолва маршрута', [
                'analysis_id' => $analysis->id,
                'purpose' => $purpose->value,
                'document_type' => $documentType,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
