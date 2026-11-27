<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use Spatie\LaravelData\Data;

/**
 * Ответ LLM на шаге extraction:discovery (docs/specs/v2/08-estimate-extraction.md §3.4). Новый
 * контракт — в v1 «несколько смет в файле» системно не решалось. Вызывается на каждое окно
 * страниц оконного fallback-пути (§4.2 Стадия 1); модель НЕ видит документ целиком и не решает
 * сегментацию сама — она лишь размечает маркеры на видимых страницах окна. Бэкенд детерминированно
 * склеивает маркеры всех окон в сегменты {estimate_ordinal, page_start, page_end}: при
 * противоречивых маркерах на overlap-странице приоритет у более раннего окна для estimate_start и
 * более позднего — для estimate_end.
 */
final class DiscoveryResponse extends Data
{
    /**
     * @param  DiscoveryMarkerLine[]  $markers
     */
    public function __construct(
        #[Description('маркеры границ смет, найденные в текущем окне, в порядке страниц.')]
        public array $markers = [],
    ) {}
}
