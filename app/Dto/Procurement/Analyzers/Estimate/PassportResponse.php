<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\CalcMethod;
use Spatie\LaravelData\Data;

/**
 * Ответ LLM на шаге extract_passport (docs/specs/v2/08-estimate-extraction.md §3.1; сменяет v1
 * preview+fetch_info). Один вызов на весь документ (кэшированный system prompt + документ) в
 * основном пути (§4.1); в оконном пути (§4.2) — на первую страницу сегмента, footer в этом случае
 * обычно не заполняется (см. TotalsResponse).
 * - estimate_number: номер/идентификатор сметы, если указан в документе.
 * - passport: тип/обоснование/направление/адрес/программа-генератор/договор — прямое соответствие
 *   prc_estimates.passport (jsonb).
 * - currency: код валюты, по умолчанию рубль.
 * - base_year: базовый год цен (напр. 2001 для ФЕР), если указан.
 * - price_level: текстовое указание уровня цен, если явно не сводится к base_year.
 * - rows_expected: оценка числа позиций (не разделов) во всей смете — ключ для контроля полноты
 *   на бэкенде (§4.1 п.6); при оконном пути не гарантированно точен, используется как ориентир,
 *   не строгий гейт (§4а).
 * - totals: итоги/начисления, если документ виден целиком и подвал попал в контекст; иначе null —
 *   запрашиваются отдельно через extract_totals (TotalsResponse).
 * - calc_method: метод расчёта сметы — базисно-индексный или ресурсный (SR-17, P4.5); документ не
 *   называет метод явно — null, бэкенд не подставляет значение по умолчанию.
 * - labor_hours_workers / labor_hours_machinists: сводные трудозатраты рабочих/машинистов по
 *   смете (SR-07, P4.5) — заполняются, только если документ несёт сводный блок трудозатрат.
 * - document_number / document_date: реквизиты акта — номер и дата составления (SR-12, P4.5).
 * - period_start / period_end: отчётный период акта, ISO-строки (SR-12, P4.5).
 * - work_types: виды работ и их иерархия (SR-08/SR-09, P4.5) — заполняет ТОЛЬКО детерминированный
 *   маппер `.gsfx` (нормативы НР/СП, `parent_id`), LLM этот массив не порождает: боевой LLM-ответ
 *   всегда несёт пустой список (исключение из D33, research/06 §6 п.3) — `work_type_name`
 *   отдельной позиции идёт полем `EstimateRow::$work_type_name`, не через этот массив.
 */
final class PassportResponse extends Data
{
    /**
     * @param  EstimateWorkType[]  $work_types
     */
    public function __construct(
        public EstimatePassport $passport,
        public ?string $estimate_number = null,
        public string $currency = 'RUB',
        public ?int $base_year = null,
        public ?string $price_level = null,
        public int $rows_expected = 0,
        public ?EstimateTotalsBlock $totals = null,
        public ?CalcMethod $calc_method = null,
        public ?string $labor_hours_workers = null,
        public ?string $labor_hours_machinists = null,
        public ?string $document_number = null,
        public ?string $document_date = null,
        public ?string $period_start = null,
        public ?string $period_end = null,
        public array $work_types = [],
    ) {}
}
