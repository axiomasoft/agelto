<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Метод расчёта сметы (`prc_estimates.calc_method`, SR-17,
 * `plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §5.9).
 * - BaseIndex: базисно-индексный — цена берётся на базисном уровне и пересчитывается индексом.
 * - Resource: ресурсный — цена считается прямым счётом ресурсов текущего уровня, `*_base` пуст.
 */
enum CalcMethod: string
{
    case BaseIndex = 'base_index';
    case Resource = 'resource';
}
