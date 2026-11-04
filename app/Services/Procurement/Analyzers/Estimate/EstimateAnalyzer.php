<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Llm\LlmPurpose;
use App\Enums\Llm\PromptStep;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Enums\Procurement\Ai\GoldSource;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Exceptions\Llm\UnsupportedConsumer;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Fixture;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\Dto\AnalysisSummary;
use App\Services\Procurement\Contracts\Dto\SummaryMetric;
use App\Services\Procurement\Contracts\SupportsResultComparison;
use App\Services\Procurement\Contracts\SupportsTypeProfileValidation;

/**
 * Анализатор сметы: объявляет извлечение, собственные шаги и назначения, собирает сводку.
 * Типовые классы лежат в `Analyzers\Estimate\` соответствующего слоя.
 * `SupportsResultComparison` сравнивает теневой и боевой прогоны через `EvalMetrics`;
 * разбор `estimates` остаётся в анализаторе. `SupportsTypeProfileValidation` проверяет
 * пять полей профиля: анализатор возвращает ошибки, а витрина выбирает способ их показа.
 */
final readonly class EstimateAnalyzer implements DocumentAnalyzer, SupportsResultComparison, SupportsTypeProfileValidation
{
    public function __construct(
        private EvalMetrics $metrics,
    ) {}

    public function hasExtraction(): bool
    {
        return true;
    }

    public function dispatchExtraction(int $documentId): void
    {

        throw new UnsupportedConsumer;
    }

    /**
     * @return list<PromptStep>
     */
    public function promptSteps(): array
    {
        return [
            EstimatePromptStep::ExtractionGlobal,
            EstimatePromptStep::ExtractionDiscovery,
            EstimatePromptStep::ExtractionPassport,
            EstimatePromptStep::ExtractionRows,
            EstimatePromptStep::ExtractionTotals,
        ];
    }

    /**
     * @return list<LlmPurpose>
     */
    public function purposes(): array
    {
        return [
            EstimateLlmPurpose::Discovery,
            EstimateLlmPurpose::ExtractPassport,
            EstimateLlmPurpose::ExtractRows,
            EstimateLlmPurpose::ExtractTotals,
        ];
    }

    /**
     * Сводка прогона извлечения: сколько смет, сколько позиций и на какую сумму. Деньги считаются
     * `bcadd` по строковому представлению `decimal:2` — прогон через `float` терял бы копейки
     * ровно там, где их показывают. Предметного экрана у сметы пока нет, поэтому
     * `detailRoute` — `null`: маршрут появится вместе с витриной.
     */
    public function summarize(Analysis $analysis): AnalysisSummary
    {
        $estimates = $analysis->estimates()->orderBy('ordinal')->get();

        $rows = 0;
        $total = '0.00';
        $currency = null;

        foreach ($estimates as $estimate) {
            $rows += (int) ($estimate->rows_extracted ?? 0);
            $total = bcadd($total, $this->decimal($estimate->total_amount), 2);
            // Форма `?? null` — не украшение: вне `??`-контекста Larastan не резолвит тип
            // свойства модели и заводит `property.notFound`.
            $currency ??= $estimate->currency ?? null;
        }

        $count = $estimates->count();

        return new AnalysisSummary(
            title: $this->plural($count, 'смета', 'сметы', 'смет').', '.$this->plural($rows, 'позиция', 'позиции', 'позиций'),
            metrics: [
                new SummaryMetric(label: 'Сметы', value: $count),
                new SummaryMetric(label: 'Позиции', value: $rows),
                new SummaryMetric(label: 'Сумма', value: $total, unit: $currency),
            ],
        );
    }

    /**
     * Сравнение теневого прогона с боевым — сырые payload'ы
     * `Analysis::$result`, ключ `'estimates'` разбирается ЗДЕСЬ, а не в вызывающем job'е.
     *
     * @param  array<string, mixed>  $primaryResult
     * @param  array<string, mixed>  $shadowResult
     * @return array<string, mixed>|null
     */
    public function compareResults(array $primaryResult, array $shadowResult): ?array
    {
        $primaryEstimates = $this->estimateList($primaryResult['estimates'] ?? null);
        $shadowEstimates = $this->estimateList($shadowResult['estimates'] ?? null);

        if ($primaryEstimates === [] || $shadowEstimates === []) {
            return null;
        }

        return $this->metrics->compare(goldEstimates: $primaryEstimates, predictedEstimates: $shadowEstimates);
    }

    /**
     * Полная валидация пяти полей профиля: все ошибки возвращаются вместе, без исключений.
     * Состав закрытый — ключ вне пяти известных полей считается ошибкой. `form` валидируется ПРОТИВ `Fixture::CODE_PATTERN` (единственный носитель
     * контракта кода) — синтетический код с валидными EST/XLSX-сегментами
     * и NNN-хвостом матчится целиком, второй регэксп на третий сегмент не заводится.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, list<string>>
     */
    public function validateTypeProfile(array $profile): array
    {
        $errors = [];

        $knownKeys = ['class', 'form', 'gold_source', 'expected_estimates', 'expected_rows'];
        $unknownKeys = array_diff(array_keys($profile), $knownKeys);

        if ($unknownKeys !== []) {
            $errors[''] = ['Неизвестные поля профиля: '.implode(', ', $unknownKeys).'.'];
        }

        if (! array_key_exists('class', $profile) || ! is_string($profile['class']) || FixtureClass::tryFrom($profile['class']) === null) {
            $errors['class'] = ['Поле "class" обязано быть одним из: '.$this->enumValues(FixtureClass::cases()).'.'];
        }

        if (! array_key_exists('form', $profile) || ! is_string($profile['form']) || $profile['form'] === '' || ! $this->isValidForm($profile['form'])) {
            $errors['form'] = ['Поле "form" обязано быть строкой из 2–10 заглавных латинских букв/цифр (третий сегмент кода эталона).'];
        }

        if (! array_key_exists('gold_source', $profile) || ! is_string($profile['gold_source']) || GoldSource::tryFrom($profile['gold_source']) === null) {
            $errors['gold_source'] = ['Поле "gold_source" обязано быть одним из: '.$this->enumValues(GoldSource::cases()).'.'];
        }

        foreach (['expected_estimates', 'expected_rows'] as $field) {
            if (array_key_exists($field, $profile) && $profile[$field] !== null && ! $this->isNonNegativeInteger($profile[$field])) {
                $errors[$field] = ["Поле \"{$field}\" обязано быть целым числом ≥ 0 либо отсутствовать."];
            }
        }

        return $errors;
    }

    /**
     * Третий сегмент `Fixture::CODE_PATTERN` (`form`) проверяется через синтетический код с
     * фиксированными валидными первым/вторым/четвёртым сегментами — единственный носитель
     * контракта кода эталона остаётся `Fixture::CODE_PATTERN`.
     */
    private function isValidForm(string $form): bool
    {
        return preg_match(Fixture::CODE_PATTERN, "EST-XLSX-{$form}-000") === 1;
    }

    private function isNonNegativeInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        return is_string($value) && $value !== '' && ctype_digit($value);
    }

    /**
     * @param  list<FixtureClass|GoldSource>  $cases
     */
    private function enumValues(array $cases): string
    {
        return implode(', ', array_map(static fn (FixtureClass|GoldSource $case): string => $case->value, $cases));
    }

    /**
     * Русская форма числительного — «1 смета / 2 сметы / 5 смет». Витрине отдаётся готовая
     * подпись, потому что склонение зависит от СЛОВА, а не от числа: разбирать его в презентации
     * значило бы вернуть туда знание о предмете.
     */
    private function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        $form = match (true) {
            $mod100 >= 11 && $mod100 <= 14 => $many,
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };

        return "{$count} {$form}";
    }

    /** @return numeric-string */
    private function decimal(?string $value): string
    {
        $value ??= '0';

        if (! is_numeric($value)) {
            throw new \UnexpectedValueException("Expected decimal value, got {$value}.");
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function estimateList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $estimates = [];

        foreach ($value as $estimate) {
            if (is_array($estimate)) {
                $typedEstimate = [];

                foreach ($estimate as $key => $field) {
                    if (is_string($key)) {
                        $typedEstimate[$key] = $field;
                    }
                }

                $estimates[] = $typedEstimate;
            }
        }

        return $estimates;
    }
}
