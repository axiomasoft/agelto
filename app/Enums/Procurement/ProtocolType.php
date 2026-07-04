<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Закрытый codelist типов протокола для 44-ФЗ. Для 223-ФЗ типы непредсказуемы —
 * `prc_protocols.type` в БД остаётся простым text-полем без Postgres CHECK;
 * этот enum применяется опционально (валидация 44-ФЗ-кейсов), произвольная
 * строка (в т.ч. `прочее_223`) в БД допустима и enum не блокирует.
 */
enum ProtocolType: string
{
    case FirstPartsReview = 'рассмотрение_первых_частей';
    case SecondPartsReview = 'рассмотрение_вторых_частей';
    case Auction = 'проведение_аукциона';
    case Evaluation = 'оценка';
    case Summary = 'подведение_итогов';
    case Failed = 'несостоявшаяся';
    case SingleSupplierJustification = 'обоснование_ед_поставщика';
    case Other223 = 'прочее_223';
}
