<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Llm\LlmPurpose;
use App\Enums\Llm\PromptStep;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Enums\Procurement\Ai\GoldSource;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Fixture;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\Dto\AnalysisSummary;
use App\Services\Procurement\Contracts\Dto\SummaryMetric;
use App\Services\Procurement\Contracts\SupportsResultComparison;
use App\Services\Procurement\Contracts\SupportsTypeProfileValidation;

/**
 * Смета как тип документа — первая реализация `DocumentAnalyzer` (§6.1 контракта P2.1:
 * всё, что принадлежит типу, лежит по пути с сегментом `Analyzers\Estimate\` в СВОЁМ слое).
 *
 * На этом шаге анализатор — тонкая обёртка: он объявляет наличие извлечения, свой job, свои шаги
 * и назначения и собирает сводку результата, а внутрь зовёт существующие классы по их
 * СЕГОДНЯШНИМ путям. Переезд шестнадцати сметных единиц и самого job'а в этот каталог — P2.4,
 * расщепление enum'ов шагов/назначений на ядерные и сметные — P2.5.
 *
 * Реализует `SupportsResultComparison` (§1.5 контракта P2.1, D106) — сравнение теневого прогона
 * с боевым (`EvalMetrics`) и разбор ключа `'estimates'` payload'а живут здесь, а не в ядерном
 * `ShadowAnalysisJob`. Реализует `SupportsTypeProfileValidation` (§1.6 контракта P2.1, D132) —
 * пять полей `type_profile` §6.2 root/document-type-axis.md проверяются здесь, а не в витрине
 * P5.1 (D43): экран решает, КАК показать сообщения, анализатор — ЧТО в профиле не так.
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
        EstimateExtractionJob::dispatch($documentId)->afterCommit();
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
     * ровно там, где их показывают (§3 контракта). Предметного экрана у сметы пока нет, поэтому
     * `detailRoute` — `null`: маршрут появится вместе с витриной P5.
     */
    public function summarize(Analysis $analysis): AnalysisSummary
    {
        $estimates = $analysis->estimates()->orderBy('ordinal')->get();

        $rows = 0;
        $total = '0.00';
        $currency = null;

        foreach ($estimates as $estimate) {
            $rows += (int) ($estimate->rows_extracted ?? 0);
            $total = bcadd($total, (string) ($estimate->total_amount ?? '0'), 2);
            // Форма `?? null` — не украшение: вне `??`-контекста Larastan не резолвит тип
            // свойства модели и заводит `property.notFound` (тот же приём, что P2.2).
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
     * Сравнение теневого прогона с боевым (§1.5 контракта P2.1, D106) — сырые payload'ы
     * `Analysis::$result`, ключ `'estimates'` разбирается ЗДЕСЬ, а не в вызывающем job'е.
     *
     * @param  array<string, mixed>  $primaryResult
     * @param  array<string, mixed>  $shadowResult
     * @return array<string, mixed>|null
     */
    public function compareResults(array $primaryResult, array $shadowResult): ?array
    {
        $primaryEstimates = (array) ($primaryResult['estimates'] ?? []);
        $shadowEstimates = (array) ($shadowResult['estimates'] ?? []);

        if ($primaryEstimates === [] || $shadowEstimates === []) {
            return null;
        }

        return $this->metrics->compare(goldEstimates: $primaryEstimates, predictedEstimates: $shadowEstimates);
    }

    /**
     * Валидация пяти полей профиля §6.2 root/document-type-axis.md (§1.6 контракта P2.1, D132) —
     * ДОСЛОВНО по инвариантам: полная проверка (все поля разом, инвариант 5), не бросает
     * (инвариант 4), закрытый состав — ключ вне пяти известных полей есть ошибка (Implementation
     * Rule 6, P5.1). `form` валидируется ПРОТИВ `Fixture::CODE_PATTERN` (единственный носитель
     * контракта кода, Implementation Rule 2) — синтетический код с валидными EST/XLSX-сегментами
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

        if (! array_key_exists('class', $profile) || FixtureClass::tryFrom((string) $profile['class']) === null) {
            $errors['class'] = ['Поле "class" обязано быть одним из: '.$this->enumValues(FixtureClass::cases()).'.'];
        }

        if (! array_key_exists('form', $profile) || ! is_string($profile['form']) || $profile['form'] === '' || ! $this->isValidForm($profile['form'])) {
            $errors['form'] = ['Поле "form" обязано быть строкой из 2–10 заглавных латинских букв/цифр (третий сегмент кода эталона).'];
        }

        if (! array_key_exists('gold_source', $profile) || GoldSource::tryFrom((string) $profile['gold_source']) === null) {
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
     * контракта кода эталона остаётся `Fixture::CODE_PATTERN` (D13).
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
}
