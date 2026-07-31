<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

/**
 * Порт исходящего прокси для домена `Llm` (research/02 §9.2, D16) — узкий, без утечки моделей
 * Eloquent: домен `Llm` не знает про прокси-пул, скоуп, страны, только про эту опцию транспорта.
 * Реализация — адаптер `PoolOutboundProxy` со стороны прокси-слоя.
 */
interface OutboundProxy
{
    /**
     * @return array{proxy?: string} вход `Http::withOptions()`/`withClientOptions()`; `[]` —
     *                               прокси для этого хоста не требуется/не назначено
     */
    public function optionsFor(string $host): array;
}
