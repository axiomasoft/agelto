<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

use App\Services\Llm\Dto\UsageQuery;
use App\Services\Llm\Dto\UsageReport;

/**
 * Провайдер-агностичный источник расхода LLM (контракт `root/usage-source-contract.md`, D10/D34).
 *
 * ОДИН метод и ОДНА форма ответа на всех потребителей: консоль (`ai:cost-report`), Pulse-карточка,
 * виджет дашборда, процедура сверки. Реализация сегодня одна — `App\Services\Llm\TrackingUsageSource`
 * («наш трекинг» поверх `llm_provider_calls`); вторая — биллинг провайдера поверх экспорта Cloud Billing в
 * BigQuery — описана в контракте §5 ТЕКСТОМ и класса-заглушки не имеет: пустая реализация была бы
 * мёртвым кодом (D34).
 *
 * Сейм оправдан не гипотетическим адаптером, а тем, что каждая цифра расхода обязана нести подпись
 * источника и лага: молчаливое смешение «наших» и «провайдерских» цифр — механизм, по которому
 * инцидент D126 EST-TRAIN оставался невидимым.
 */
interface UsageSource
{
    public function usage(UsageQuery $query): UsageReport;
}
