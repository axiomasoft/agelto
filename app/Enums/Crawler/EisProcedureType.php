<?php

declare(strict_types=1);

namespace App\Enums\Crawler;

/**
 * Закрытая карта способов определения поставщика по 44-ФЗ (колонка CSV-экспорта
 * ЕИС `methodOrderPurchaseCsv` → «Способ определения поставщика», `research/eis-csv-recon.md`).
 * 223-ФЗ способы закупки — свободный текст заказчика, не закрытый список — сюда не попадают.
 */
enum EisProcedureType: string
{
    case ElectronicAuction = 'electronic_auction';
    case OpenCompetition = 'open_competition';
    case LimitedParticipationCompetition = 'limited_participation_competition';
    case TwoStageCompetition = 'two_stage_competition';
    case QuotationRequest = 'quotation_request';
    case ProposalRequest = 'proposal_request';
    case SingleSupplier = 'single_supplier';

    /**
     * Сопоставление сырой русской строки способа закупки (44-ФЗ) → case.
     * Case-insensitive, trim; `null` — способ не из закрытого списка 44-ФЗ
     * (223-ФЗ либо новый/неизвестный способ) — сырьё остаётся в `procedure_type_raw`.
     */
    public static function fromRawLabel(string $raw): ?self
    {
        $normalized = mb_strtolower(trim($raw));

        return match ($normalized) {
            'электронный аукцион' => self::ElectronicAuction,
            'открытый конкурс в электронной форме', 'конкурс в электронной форме' => self::OpenCompetition,
            'конкурс с ограниченным участием в электронной форме' => self::LimitedParticipationCompetition,
            'двухэтапный конкурс в электронной форме' => self::TwoStageCompetition,
            'запрос котировок в электронной форме' => self::QuotationRequest,
            'запрос предложений в электронной форме' => self::ProposalRequest,
            'закупка у единственного поставщика (подрядчика, исполнителя)', 'закупка у единственного поставщика' => self::SingleSupplier,
            default => null,
        };
    }
}
