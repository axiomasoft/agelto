<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Метод расчёта сметы в `prc_estimates.calc_method`.
 * - BaseIndex: базисно-индексный — цена берётся на базисном уровне и пересчитывается индексом.
 * - Resource: ресурсный — цена считается прямым счётом ресурсов текущего уровня, `*_base` пуст.
 */
enum CalcMethod: string
{
    case BaseIndex = 'base_index';
    case Resource = 'resource';
}
