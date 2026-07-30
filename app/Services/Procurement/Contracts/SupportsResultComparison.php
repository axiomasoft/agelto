<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

/**
 * Опциональный контракт сравнения результата теневого прогона с боевым (§1.5 контракта P2.1,
 * D106) — не всякий тип структурно сравним, поэтому способность живёт РЯДОМ с `DocumentAnalyzer`,
 * а не его обязательным членом: ядро спрашивает `instanceof`, отсутствие реализации — штатный
 * `null`, не ошибка.
 *
 * Payload'ы — СЫРЫЕ (`Analysis::$result`), разбор формы результата типа (ключи, вложенность)
 * остаётся ВНУТРИ реализации: ядро, доставшее эти ключи само, знало бы предмет типа так же, как
 * знало бы его, импортируя метрику напрямую.
 */
interface SupportsResultComparison
{
    /**
     * @param  array<string, mixed>  $primaryResult
     * @param  array<string, mixed>  $shadowResult
     * @return array<string, mixed>|null
     */
    public function compareResults(array $primaryResult, array $shadowResult): ?array;
}
