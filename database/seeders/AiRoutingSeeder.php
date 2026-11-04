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
 * Стартовая матрица роутинга: провайдер `gemini` и 11 строк `llm_routes`.
 * Screening, discovery и три extraction-purpose получают основной маршрут (`priority=0`)
 * и эскалационный (`priority=1`); `prompt_critic` получает один основной маршрут.
 *
 * Driver `gemini` соответствует `LlmProviderFactory`; имя транспортной библиотеки
 * не является значением поля `driver`.
 *
 * Идемпотентен: `updateOrCreate` по `code` провайдера и по
 * `(document_type, purpose, priority, shadow=false)` маршрута. Повторный `db:seed`
 * обновляет provider/model/options/is_active без создания дублей.
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

            // Неизвестный purpose отклоняется реестром до записи маршрута.
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

        // Общие настройки обоих приоритетов трёх extraction-purpose:
        // bounded output и LOW thinking. Допустимые пределы проверяет capability policy модели.
        $extractionOptions = ['max_tokens' => 65536, 'thinking_level' => 'LOW'];

        return [
            // (any, screening) — общий дешёвый гейт пайплайна для ЛЮБОГО типа документа.
            ['document_type' => $any, 'purpose' => CoreLlmPurpose::Screening->value, 'priority' => 0, 'model' => 'gemini-3.1-flash-lite'],
            ['document_type' => $any, 'purpose' => CoreLlmPurpose::Screening->value, 'priority' => 1, 'model' => 'gemini-3.1-pro-preview'],

            // (estimate, discovery) — маркеры границ смет оконного пути, дёшево.
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::Discovery->value, 'priority' => 0, 'model' => 'gemini-3.1-flash-lite'],
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::Discovery->value, 'priority' => 1, 'model' => 'gemini-3.1-pro-preview'],

            // (estimate, extract_passport|extract_rows|extract_totals) — рабочая лошадка.
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractPassport->value, 'priority' => 0, 'model' => 'gemini-3.5-flash', 'options' => $extractionOptions],
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractPassport->value, 'priority' => 1, 'model' => 'gemini-3.1-pro-preview', 'options' => $extractionOptions],

            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractRows->value, 'priority' => 0, 'model' => 'gemini-3.5-flash', 'options' => $extractionOptions],
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractRows->value, 'priority' => 1, 'model' => 'gemini-3.1-pro-preview', 'options' => $extractionOptions],

            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractTotals->value, 'priority' => 0, 'model' => 'gemini-3.5-flash', 'options' => $extractionOptions],
            ['document_type' => $estimate, 'purpose' => EstimateLlmPurpose::ExtractTotals->value, 'priority' => 1, 'model' => 'gemini-3.1-pro-preview', 'options' => $extractionOptions],

            // (any, prompt_critic) — критик патчей промтов, на дешёвой модели.
            ['document_type' => $any, 'purpose' => CoreLlmPurpose::PromptCritic->value, 'priority' => 0, 'model' => 'gemini-3.1-flash-lite'],
        ];
    }
}
