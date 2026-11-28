<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate\Eval;

use App\Services\Procurement\Analyzers\Estimate\Core\DecimalNormalizer;

final readonly class EvalRowMatcher
{
    private const string TOLERANCE = '0.01';

    private const int SCALE = 4;

    /**
     * Явный парный проход «gold ↔ предсказание» — единственный носитель правила
     * сопоставления смет:
     * 1) точное совпадение нормализованного непустого `estimate_number`;
     * 2) точное совпадение нормализованного непустого `passport.address`;
     * 3) позиция среди оставшихся несопоставленными (относительный порядок сохраняется).
     *
     * @param  list<array<string, mixed>>  $goldEstimates
     * @param  list<array<string, mixed>>  $predictedEstimates
     * @return list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>
     */
    public function matchEstimates(array $goldEstimates, array $predictedEstimates): array
    {
        $unpairedGold = array_keys($goldEstimates);
        $unpairedPredicted = array_keys($predictedEstimates);

        $numberMatch = $this->matchByExactKey(
            $unpairedGold,
            $unpairedPredicted,
            $this->normalizedKeyMap($goldEstimates, static fn (array $estimate): mixed => $estimate['estimate_number'] ?? null),
            $this->normalizedKeyMap($predictedEstimates, static fn (array $estimate): mixed => $estimate['estimate_number'] ?? null),
            'estimate_number',
        );

        $addressMatch = $this->matchByExactKey(
            $numberMatch['unpairedGold'],
            $numberMatch['unpairedPredicted'],
            $this->normalizedKeyMap($goldEstimates, static fn (array $estimate): mixed => is_array($estimate['passport'] ?? null) ? $estimate['passport']['address'] ?? null : null),
            $this->normalizedKeyMap($predictedEstimates, static fn (array $estimate): mixed => is_array($estimate['passport'] ?? null) ? $estimate['passport']['address'] ?? null : null),
            'address',
        );

        $unpairedGold = $addressMatch['unpairedGold'];
        $unpairedPredicted = $addressMatch['unpairedPredicted'];
        $positionCount = min(count($unpairedGold), count($unpairedPredicted));
        $positionPairs = [];

        for ($i = 0; $i < $positionCount; $i++) {
            $positionPairs[] = [$unpairedGold[$i], $unpairedPredicted[$i], 'position'];
        }

        /** @var list<array{gold_index: int|null, predicted_index: int|null, rule: string|null}> $entries */
        $entries = [];

        foreach ([...$numberMatch['pairs'], ...$addressMatch['pairs'], ...$positionPairs] as [$goldIndex, $predictedIndex, $rule]) {
            $entries[] = ['gold_index' => $goldIndex, 'predicted_index' => $predictedIndex, 'rule' => $rule];
        }

        foreach (array_slice($unpairedGold, $positionCount) as $goldIndex) {
            $entries[] = ['gold_index' => $goldIndex, 'predicted_index' => null, 'rule' => null];
        }

        foreach (array_slice($unpairedPredicted, $positionCount) as $predictedIndex) {
            $entries[] = ['gold_index' => null, 'predicted_index' => $predictedIndex, 'rule' => null];
        }

        usort($entries, static fn (array $a, array $b): int => [$a['gold_index'] ?? PHP_INT_MAX, $a['predicted_index'] ?? PHP_INT_MAX]
            <=> [$b['gold_index'] ?? PHP_INT_MAX, $b['predicted_index'] ?? PHP_INT_MAX]);

        /** @var list<array{slot: int, gold_index: int|null, predicted_index: int|null, rule: string|null}> $result */
        $result = [];

        foreach ($entries as $slot => $entry) {
            $result[] = ['slot' => $slot, ...$entry];
        }

        return $result;
    }

    public function fieldsMatch(mixed $gold, mixed $predicted, bool $numeric): bool
    {
        $goldPresent = $this->isPresent($gold);
        $predictedPresent = $this->isPresent($predicted);

        if (! $goldPresent && ! $predictedPresent) {
            return true;
        }

        return $goldPresent && $predictedPresent && $this->valuesMatch($gold, $predicted, $numeric);
    }

    public function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    public function valuesMatch(mixed $gold, mixed $predicted, bool $numeric): bool
    {
        if ($numeric) {
            return $this->numbersEqual($this->scalarString($gold), $this->scalarString($predicted));
        }

        return $this->normalizeText($this->scalarString($gold)) === $this->normalizeText($this->scalarString($predicted));
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     * @return array<string, array<string, mixed>> первая строка по ключу "{слот}:{row_index}", повторные по порядку
     */
    public function flattenRows(array $estimates, array $matches, bool $forGold): array
    {
        $slotByIndex = $this->slotByOriginalIndex($matches, $forGold);
        $flat = [];
        $occurrences = [];

        foreach ($estimates as $estimateIndex => $estimate) {
            $slot = $slotByIndex[$estimateIndex] ?? null;

            if ($slot === null) {
                continue;
            }

            $rows = $estimate['rows'] ?? [];

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                /** @var array<string, mixed> $row */
                $rowIndex = $row['row_index'] ?? null;

                if (! is_int($rowIndex) && ! is_string($rowIndex)) {
                    continue;
                }

                $key = $this->occurrenceKey(key: "{$slot}:{$rowIndex}", occurrences: $occurrences);
                $flat[$key] = $row;
            }
        }

        return $flat;
    }

    /**
     * Разбирает ключ строки из {@see flattenRows()}: `"{slot}:{row_index}"` или `"duplicate:{occ}:{slot}:{row_index}"`.
     *
     * @return array{slot: int, row_index: int}
     */
    public static function parseRowKey(string $key): array
    {
        if (str_starts_with($key, 'duplicate:')) {
            $parts = explode(':', $key, 4);

            if (count($parts) !== 4) {
                throw new \InvalidArgumentException("Invalid duplicate row key: {$key}");
            }

            return ['slot' => (int) $parts[2], 'row_index' => (int) $parts[3]];
        }

        $parts = explode(':', $key, 2);

        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Invalid row key: {$key}");
        }

        return ['slot' => (int) $parts[0], 'row_index' => (int) $parts[1]];
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function flattenPassport(array $estimates, array $matches, bool $forGold): array
    {
        $slotByIndex = $this->slotByOriginalIndex($matches, $forGold);
        $flat = [];

        foreach ($estimates as $estimateIndex => $estimate) {
            $slot = $slotByIndex[$estimateIndex] ?? null;

            if ($slot === null) {
                continue;
            }

            $passport = (array) ($estimate['passport'] ?? []);

            $flat[$slot] = [
                'estimate_number' => $estimate['estimate_number'] ?? null,
                'currency' => $estimate['currency'] ?? null,
                'base_year' => $estimate['base_year'] ?? null,
                'price_level' => $estimate['price_level'] ?? null,
                'calc_method' => $estimate['calc_method'] ?? null,
                'labor_hours_workers' => $estimate['labor_hours_workers'] ?? null,
                'labor_hours_machinists' => $estimate['labor_hours_machinists'] ?? null,
                'document_number' => $estimate['document_number'] ?? null,
                'document_date' => $estimate['document_date'] ?? null,
                'period_start' => $estimate['period_start'] ?? null,
                'period_end' => $estimate['period_end'] ?? null,
                'passport.type' => $passport['type'] ?? null,
                'passport.reference' => $passport['reference'] ?? null,
                'passport.target' => $passport['target'] ?? null,
                'passport.address' => $passport['address'] ?? null,
                'passport.generator' => $passport['generator'] ?? null,
                'passport.contract_number' => $passport['contract_number'] ?? null,
                'passport.contract_date' => $passport['contract_date'] ?? null,
            ];
        }

        return $flat;
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     * @return array<string, array{estimate_index: int, label: string, value: mixed, kind: mixed, chapter_number: mixed, rate: mixed}>
     */
    public function flattenTotals(array $estimates, array $matches, bool $forGold): array
    {
        $slotByIndex = $this->slotByOriginalIndex($matches, $forGold);
        $flat = [];
        $occurrences = [];

        foreach ($estimates as $estimateIndex => $estimate) {
            $slot = $slotByIndex[$estimateIndex] ?? null;

            if ($slot === null) {
                continue;
            }

            $totals = $estimate['totals'] ?? [];
            $footer = is_array($totals) && is_array($totals['footer'] ?? null) ? array_values($totals['footer']) : [];
            $this->flattenTotalLines(lines: $footer, slot: $slot, flat: $flat, occurrences: $occurrences);
        }

        return $flat;
    }

    public function numbersEqual(string $a, string $b): bool
    {
        $normA = DecimalNormalizer::normalize($a);
        $normB = DecimalNormalizer::normalize($b);

        if ($normA === null || $normB === null) {
            return $this->normalizeText($a) === $this->normalizeText($b);
        }

        $diff = bcsub($normA, $normB, self::SCALE);
        $abs = bccomp($diff, '0', self::SCALE) < 0 ? bcmul($diff, '-1', self::SCALE) : $diff;

        return bccomp($abs, self::TOLERANCE, self::SCALE) <= 0;
    }

    public function normalizeText(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    public function scalarString(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        throw new \RuntimeException('Метрика ожидает скалярное значение поля.');
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @param  callable(array<string, mixed>): mixed  $extractor
     * @return array<int, ?string>
     */
    private function normalizedKeyMap(array $estimates, callable $extractor): array
    {
        $map = [];

        foreach ($estimates as $index => $estimate) {
            $value = $extractor($estimate);
            $map[$index] = $this->isPresent($value) ? $this->normalizeText($this->scalarString($value)) : null;
        }

        return $map;
    }

    /**
     * @param  list<int>  $unpairedGold
     * @param  list<int>  $unpairedPredicted
     * @param  array<int, ?string>  $goldKeys
     * @param  array<int, ?string>  $predictedKeys
     * @return array{pairs: list<array{0: int, 1: int, 2: string}>, unpairedGold: list<int>, unpairedPredicted: list<int>}
     */
    private function matchByExactKey(array $unpairedGold, array $unpairedPredicted, array $goldKeys, array $predictedKeys, string $rule): array
    {
        $goldCounts = [];

        foreach ($unpairedGold as $goldIndex) {
            $key = $goldKeys[$goldIndex] ?? null;

            if ($key !== null) {
                $goldCounts[$key] = ($goldCounts[$key] ?? 0) + 1;
            }
        }

        $predictedCounts = [];
        $predictedIndexByKey = [];

        foreach ($unpairedPredicted as $predictedIndex) {
            $key = $predictedKeys[$predictedIndex] ?? null;

            if ($key !== null) {
                $predictedCounts[$key] = ($predictedCounts[$key] ?? 0) + 1;
                $predictedIndexByKey[$key] = $predictedIndex;
            }
        }

        $pairs = [];
        $matchedGold = [];
        $matchedPredicted = [];

        foreach ($unpairedGold as $goldIndex) {
            $key = $goldKeys[$goldIndex] ?? null;

            if ($key === null || $goldCounts[$key] !== 1 || ($predictedCounts[$key] ?? 0) !== 1) {
                continue;
            }

            $predictedIndex = $predictedIndexByKey[$key];
            $pairs[] = [$goldIndex, $predictedIndex, $rule];
            $matchedGold[] = $goldIndex;
            $matchedPredicted[] = $predictedIndex;
        }

        return [
            'pairs' => $pairs,
            'unpairedGold' => array_values(array_diff($unpairedGold, $matchedGold)),
            'unpairedPredicted' => array_values(array_diff($unpairedPredicted, $matchedPredicted)),
        ];
    }

    /**
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     * @return array<int, int>
     */
    private function slotByOriginalIndex(array $matches, bool $forGold): array
    {
        $side = $forGold ? 'gold_index' : 'predicted_index';
        $map = [];

        foreach ($matches as $entry) {
            if ($entry[$side] !== null) {
                $map[$entry[$side]] = $entry['slot'];
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $occurrences
     */
    private function occurrenceKey(string $key, array &$occurrences): string
    {
        $occurrence = $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;

        return $occurrence === 1 ? $key : "duplicate:{$occurrence}:{$key}";
    }

    /**
     * @param  list<mixed>  $lines
     * @param  array<string, array{estimate_index: int, label: string, value: mixed, kind: mixed, chapter_number: mixed, rate: mixed}>  $flat
     * @param  array<string, int>  $occurrences
     */
    private function flattenTotalLines(array $lines, int $slot, array &$flat, array &$occurrences): void
    {
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            /** @var array<string, mixed> $line */
            $label = is_string($line['label'] ?? null) ? $line['label'] : '';
            $kind = $line['kind'] ?? null;
            $key = $this->occurrenceKey(
                key: $slot.'::'.$this->normalizeText($label).'::'.$this->scalarString($kind ?? ''),
                occurrences: $occurrences,
            );

            $flat[$key] = [
                'estimate_index' => $slot,
                'label' => $label,
                'value' => $line['value'] ?? null,
                'kind' => $kind,
                'chapter_number' => $line['chapter_number'] ?? null,
                'rate' => $line['rate'] ?? null,
            ];

            $children = $line['children'] ?? [];
            $this->flattenTotalLines(
                lines: is_array($children) ? array_values($children) : [],
                slot: $slot,
                flat: $flat,
                occurrences: $occurrences,
            );
        }
    }
}
