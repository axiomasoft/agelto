<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Alias;
use App\Attributes\Ai\Description;
use App\Enums\Procurement\Estimate\CalcMethod;
use Spatie\LaravelData\Data;

/**
 * Ответ LLM на шаге extract_passport (docs/specs/v2/08-estimate-extraction.md §3.1; сменяет v1
 * preview+fetch_info). Один вызов на весь документ (кэшированный system prompt + документ) в
 * основном пути (§4.1); в оконном пути (§4.2) — на первую страницу сегмента, footer в этом случае
 * обычно не заполняется (см. TotalsResponse).
 */
final class PassportResponse extends Data
{
    /**
     * @param  EstimateWorkType[]  $work_types
     */
    public function __construct(
        #[Description('тип/обоснование/направление/адрес/программа-генератор/договор — прямое соответствие prc_estimates.passport (jsonb).')]
        public EstimatePassport $passport,
        #[Description('номер/идентификатор сметы, если указан в документе.')]
        #[Alias(aliases: ['Смета №', 'Локальная смета №'])]
        public ?string $estimate_number = null,
        #[Description('код валюты, по умолчанию рубль.')]
        public string $currency = 'RUB',
        #[Description('базовый год цен (напр. 2001 для ФЕР), если указан.')]
        #[Alias(aliases: ['в ценах … г.', 'уровень цен'])]
        public ?int $base_year = null,
        #[Description('текстовое указание уровня цен, если явно не сводится к base_year.')]
        public ?string $price_level = null,
        #[Description('оценка числа позиций (не разделов) во всей смете — ключ для контроля полноты на бэкенде; при оконном пути не гарантированно точен, используется как ориентир, не строгий гейт.')]
        public int $rows_expected = 0,
        #[Description('итоги/начисления, если документ виден целиком и подвал попал в контекст; иначе null — запрашиваются отдельно через extract_totals.')]
        public ?EstimateTotalsBlock $totals = null,
        #[Description('метод расчёта сметы — базисно-индексный или ресурсный; документ не называет метод явно — null, бэкенд не подставляет значение по умолчанию.')]
        public ?CalcMethod $calc_method = null,
        #[Description('сводные трудозатраты рабочих по смете — заполняются, только если документ несёт сводный блок трудозатрат.')]
        #[Alias(aliases: ['Трудозатраты рабочих, чел.-ч'])]
        public ?string $labor_hours_workers = null,
        #[Description('сводные трудозатраты машинистов по смете — заполняются, только если документ несёт сводный блок трудозатрат.')]
        #[Alias(aliases: ['Затраты труда машинистов'])]
        public ?string $labor_hours_machinists = null,
        #[Description('реквизиты акта — номер составления.')]
        #[Alias(aliases: ['Акт №'])]
        public ?string $document_number = null,
        #[Description('реквизиты акта — дата составления.')]
        #[Alias(aliases: ['от … г.'])]
        public ?string $document_date = null,
        #[Description('отчётный период акта, ISO-строка (начало).')]
        #[Alias(aliases: ['Отчётный период с … по …'])]
        public ?string $period_start = null,
        #[Description('отчётный период акта, ISO-строка (конец).')]
        #[Alias(aliases: ['Отчётный период с … по …'])]
        public ?string $period_end = null,
        #[Description('виды работ и их иерархия — заполняет ТОЛЬКО детерминированный маппер .gsfx (нормативы НР/СП, parent_id), LLM этот массив не порождает: боевой LLM-ответ всегда несёт пустой список — work_type_name отдельной позиции идёт полем EstimateRow::$work_type_name, не через этот массив.')]
        public array $work_types = [],
    ) {}
}
