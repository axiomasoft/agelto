<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Illuminate\Database\Seeder;

/**
 * Стартовая матрица роутинга (ТЗ 04 §3.1, D4/D27/D37/D61): провайдер `gemini` + 11 строк
 * `llm_routes` — 5 основных (`priority=0`, дешёвая модель на каждый purpose) + 5
 * эскалационных (`priority=1`, D4 «эскалация по флагу сложности/провалу валидации») + 1 строка
 * `prompt_critic` (`priority=0` без эскалации, P7.4, D61) — критик промтов не re-ask'ается на
 * маршруте выше, повтор — на уровне job-retry, как у остальных шагов.
 *
 * Driver провайдера — `gemini` (не буквальное «prism-gemini» из прозы Intent этого item'а):
 * `LlmRouteResolver::providerFor()`/`LlmRouteFactory` уже закрепили `driver === 'gemini'` как
 * код-контракт (P3.3) — сид с «prism-gemini» ломал бы резолв (`RuntimeException` «Неизвестный
 * драйвер провайдера»). Prism — библиотека ТРАНСПОРТА за адаптером, не значение поля `driver`.
 *
 * Идемпотентен: `updateOrCreate` по `code` (провайдер) и по `(document_type, purpose, priority)`
 * (маршрут — тот же составной ключ, что `unique()` в миграции `2026_07_04_100028`) — повторный
 * `db:seed` не плодит дублей, только освежает `provider_id`/`model`/`is_active`/`shadow`.
 *
 * Миграция на 3.x-преемников (D27 план EST-AI + D123 план 2026.07.26-EST-TRAIN, 2026-07-28):
 * линейка 2.5 (`flash`/`flash-lite`/`pro`) недоступна активному ключу владельца ЦЕЛИКОМ
 * («404 NOT_FOUND — no longer available to new users», боевой вызов подтвердил на всех трёх
 * моделях; известный, признанный Google баг/ограничение для новых GCP-проектов, июль 2026,
 * RAG:✅ Perplexity 2026-07-28) — раньше официального EOL (2026-10-16). Активные модели ниже
 * переключены на преемников из `config('ai.capabilities')` (D123); строки 2.5-линейки оставлены
 * закомментированными как историческая точка отката, если Google откатит баг.
 */
final class AiRoutingSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(DocumentAnalyzerRegistry::class);

        $provider = Provider::query()->updateOrCreate(
            ['code' => 'gemini'],
            [
                'driver' => 'gemini',
                'base_url' => null,
                'options' => null,
                'is_active' => true,
            ],
        );

        foreach ($this->routes() as $route) {
            $documentType = AiDocumentType::tryFrom($route['document_type']) ?? DocumentTypeScope::Any;

            // Fail fast на опечатку в purpose — реестр §7 контракта, та же граница, что PromptSeeder.
            $registry->purposeFor($documentType, $route['purpose']);

            LlmRoute::query()->updateOrCreate(
                [
                    'document_type' => $route['document_type'],
                    'purpose' => $route['purpose'],
                    'priority' => $route['priority'],
                    'shadow' => false,
                ],
                [
                    'provider_id' => $provider->id,
                    'model' => $route['model'],
                    'options' => $route['options'] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return list<array{document_type: string, purpose: string, priority: int, model: string, options?: array<string, mixed>}>
     */
    private function routes(): array
    {
        $any = DocumentTypeScope::Any->value;
        $estimate = AiDocumentType::Estimate->value;

        // Бюджет вывода и уровень размышления трёх extraction-маршрутов (D98/D99, P2.9) — узкое
        // исключение из «фаза не трогает `llm_routes`» (P2 Scope Excluded). `max_tokens` = потолок
        // вывода `gemini-3.5-flash`, подтверждённый RAG (config('ai.capabilities')) — вдвое выше
        // прежней константы-дефолта `LlmRouteResolver::DEFAULT_MAX_TOKENS`; применён к ОБЕИМ
        // строкам приоритета (эскалационная модель `gemini-3.1-pro-preview` своего подтверждённого
        // потолка не имеет — `LlmRouteResolver::assertWithinOutputCeiling()` для нее не
        // проверяет, ключа `max_output_tokens` в её capability-записи нет). `thinking_level` =
        // `LOW` — медианная доля thinking/total по уже оплаченным падениям EvalRun #1/#2/#3
        // (`findings/P2-extraction-token-budget-2026-08-01.md` §4) — 0.5303, что ≥ порога 0.4
        // из Implementation Rules P2.9.
        $extractionOptions = ['max_tokens' => 65536, 'thinking_level' => 'LOW'];

        return [
            // (any, screening) — общий дешёвый гейт пайплайна для ЛЮБОГО типа документа (D4).
            ['document_type' => $any, 'purpose' => CoreLlmPurpose::Screening->value, 'priority' => 0, 'model' => 'gemini-3.1-flash-lite'],
            // 'model' => 'gemini-2.5-flash-lite', // D27 исходная — недоступна ключу (D123), не откатывать без проверки доступа.
            ['document_type' => $any, 'purpose' => CoreLlmPurpose::Screening->value, 'priority' => 1, 'model' => 'gemini-3.1-pro-preview'],
            // 'model' => 'gemini-2.5-pro', // D27 исходная — недоступна ключу (D123).

            // (estimate, discovery) — маркеры границ смет оконного пути P6.4 (D30/D37), дёшево.
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::Discovery->value, 'priority' => 0, 'model' => 'gemini-3.1-flash-lite'],
            // 'model' => 'gemini-2.5-flash-lite', // D27 исходная — недоступна ключу (D123).
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::Discovery->value, 'priority' => 1, 'model' => 'gemini-3.1-pro-preview'],
            // 'model' => 'gemini-2.5-pro', // D27 исходная — недоступна ключу (D123).

            // (estimate, extract_passport|extract_rows|extract_totals) — рабочая лошадка (D4).
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractPassport->value, 'priority' => 0, 'model' => 'gemini-3.5-flash', 'options' => $extractionOptions],
            // 'model' => 'gemini-2.5-flash', // D27 исходная — недоступна ключу (D123).
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractPassport->value, 'priority' => 1, 'model' => 'gemini-3.1-pro-preview', 'options' => $extractionOptions],
            // 'model' => 'gemini-2.5-pro', // D27 исходная — недоступна ключу (D123).

            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractRows->value, 'priority' => 0, 'model' => 'gemini-3.5-flash', 'options' => $extractionOptions],
            // 'model' => 'gemini-2.5-flash', // D27 исходная — недоступна ключу (D123).
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractRows->value, 'priority' => 1, 'model' => 'gemini-3.1-pro-preview', 'options' => $extractionOptions],
            // 'model' => 'gemini-2.5-pro', // D27 исходная — недоступна ключу (D123).

            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractTotals->value, 'priority' => 0, 'model' => 'gemini-3.5-flash', 'options' => $extractionOptions],
            // 'model' => 'gemini-2.5-flash', // D27 исходная — недоступна ключу (D123).
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractTotals->value, 'priority' => 1, 'model' => 'gemini-3.1-pro-preview', 'options' => $extractionOptions],
            // 'model' => 'gemini-2.5-pro', // D27 исходная — недоступна ключу (D123).

            // (any, prompt_critic) — критик патчей промтов (P7.4, D61), на дешёвой модели.
            ['document_type' => $any, 'purpose' => CoreLlmPurpose::PromptCritic->value, 'priority' => 0, 'model' => 'gemini-3.1-flash-lite'],
            // 'model' => 'gemini-2.5-flash-lite', // D27 исходная — недоступна ключу (D123).
        ];
    }
}
