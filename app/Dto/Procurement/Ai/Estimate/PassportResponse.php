<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Estimate;

use Spatie\LaravelData\Data;

/**
 * Ответ LLM на шаге extract_passport (docs/specs/v2/08-estimate-extraction.md §3.1; сменяет v1
 * preview+fetch_info). Один вызов на весь документ (кэшированный system prompt + документ) в
 * основном пути (§4.1); в оконном пути (§4.2) — на первую страницу сегмента, footer в этом случае
 * обычно не заполняется (см. TotalsResponse).
 * - estimate_number: номер/идентификатор сметы, если указан в документе.
 * - passport: тип/обоснование/направление/адрес/программа-генератор — прямое соответствие
 *   prc_estimates.passport (jsonb).
 * - currency: код валюты, по умолчанию рубль.
 * - base_year: базовый год цен (напр. 2001 для ФЕР), если указан.
 * - price_level: текстовое указание уровня цен, если явно не сводится к base_year.
 * - rows_expected: оценка числа позиций (не разделов) во всей смете — ключ для контроля полноты
 *   на бэкенде (§4.1 п.6); при оконном пути не гарантированно точен, используется как ориентир,
 *   не строгий гейт (§4а).
 * - totals: итоги/начисления, если документ виден целиком и подвал попал в контекст; иначе null —
 *   запрашиваются отдельно через extract_totals (TotalsResponse).
 */
final class PassportResponse extends Data
{
    public function __construct(
        public EstimatePassport $passport,
        public ?string $estimate_number = null,
        public string $currency = 'RUB',
        public ?int $base_year = null,
        public ?string $price_level = null,
        public int $rows_expected = 0,
        public ?EstimateTotalsBlock $totals = null,
    ) {}
}
