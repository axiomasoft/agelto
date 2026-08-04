<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

/**
 * Детерминированная нормализация `unit` (B-1/B-2, P5.3 `research/06-estimate-completeness.md`
 * §4) — единица измерения контракта LLM остаётся ЦИТАТОЙ документа (SR-15 донора, вне контракта
 * не запрашивается у модели); база и множитель — производные, считает бэкенд. Тотальная функция:
 * смета может нести что угодно, исключений не бросает ни на пустом, ни на неразбираемом входе.
 */
final class UnitNormalizer
{
    private const string PATTERN = '/^(\d+(?:[.,]\d+)?)\s*(\S.*)$/u';

    /**
     * `100 м2` → `['unit_base' => 'м2', 'unit_multiplier' => '100']`; множителя нет
     * (`м3`) или разобрать не удалось (пустая строка, мусор) — `unit_base = $unit`,
     * `unit_multiplier = '1'`.
     *
     * @return array{unit_base: ?string, unit_multiplier: string}
     */
    public static function normalize(?string $unit): array
    {
        if ($unit !== null && preg_match(self::PATTERN, trim($unit), $matches) === 1) {
            return [
                'unit_base' => $matches[2],
                'unit_multiplier' => str_replace(',', '.', $matches[1]),
            ];
        }

        return ['unit_base' => $unit, 'unit_multiplier' => '1'];
    }
}
