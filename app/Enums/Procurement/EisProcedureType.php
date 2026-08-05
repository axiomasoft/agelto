<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

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
    case Part12Article93Purchase = 'part_12_article_93_purchase';
    case CapitalRepairAuction = 'capital_repair_auction';
    case PreliminarySelection = 'preliminary_selection';

    /**
     * Сопоставление сырой русской строки способа закупки (44-ФЗ) → case.
     * Case-insensitive, trim; `null` — способ не из закрытого списка 44-ФЗ
     * (223-ФЗ либо новый/неизвестный способ) — сырьё остаётся в `procedure_type_raw`.
     * Формулировки «…п. 8 ч. 1 ст. 33» (Р-2, `artifacts/eis-registry-settings.json`)
     * отображаются в ТЕ ЖЕ кейсы, что базовые: у обеих формулировок один сегмент URL
     * карточки (`ea20`/`zk20`/`ok20`), источник считает их одной процедурой.
     */
    public static function fromRawLabel(string $raw): ?self
    {
        $normalized = mb_strtolower(trim($raw));

        return match ($normalized) {
            'электронный аукцион',
            'электронный аукцион на проведение работ по строительству, реконструкции, кап. ремонту, '
            .'сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 закона № 44-фз' => self::ElectronicAuction,
            'открытый конкурс в электронной форме', 'конкурс в электронной форме',
            'открытый конкурс в электронной форме на проведение работ по строительству, реконструкции, '
            .'кап. ремонту, сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 закона '
            .'№ 44-фз' => self::OpenCompetition,
            'конкурс с ограниченным участием в электронной форме' => self::LimitedParticipationCompetition,
            'двухэтапный конкурс в электронной форме' => self::TwoStageCompetition,
            'запрос котировок в электронной форме',
            'запрос котировок в электронной форме на проведение работ по строительству, реконструкции, '
            .'кап. ремонту, сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 закона '
            .'№ 44-фз' => self::QuotationRequest,
            'запрос предложений в электронной форме' => self::ProposalRequest,
            'закупка у единственного поставщика (подрядчика, исполнителя)', 'закупка у единственного поставщика' => self::SingleSupplier,
            'закупка, осуществляемая в соответствии с частью 12 статьи 93 закона № 44-фз' => self::Part12Article93Purchase,
            'электронный аукцион на оказание услуг или выполнение работ по капитальному ремонту общего '
            .'имущества в многоквартирном доме' => self::CapitalRepairAuction,
            'предварительный отбор квалифицированных подрядных организаций' => self::PreliminarySelection,
            default => null,
        };
    }
}
