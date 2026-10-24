<?php

declare(strict_types=1);

use App\Enums\Platforms\Eis\EisProcedureType;

/**
 * Девять сырых меток `methodOrderPurchaseCsv`, наблюдённых живой разведкой P4.1
 * (`plans/2026.07.31-SYS-INTEGRITY/artifacts/eis-registry-settings.json` → `procedure_url_types`) —
 * П-7: до P4.9 карта покрывала 3 из 9.
 */
test('все девять наблюдённых сырых меток способа закупки резолвятся в case', function (string $raw, EisProcedureType $expected) {
    expect(EisProcedureType::fromRawLabel(raw: $raw))->toBe($expected);
})->with([
    'Электронный аукцион' => ['Электронный аукцион', EisProcedureType::ElectronicAuction],
    'Электронный аукцион п.8ч.1ст.33' => [
        'Электронный аукцион на проведение работ по строительству, реконструкции, кап. ремонту, '
        .'сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 Закона № 44-ФЗ',
        EisProcedureType::ElectronicAuction,
    ],
    'Запрос котировок в электронной форме' => ['Запрос котировок в электронной форме', EisProcedureType::QuotationRequest],
    'Запрос котировок п.8ч.1ст.33' => [
        'Запрос котировок в электронной форме на проведение работ по строительству, реконструкции, '
        .'кап. ремонту, сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 Закона '
        .'№ 44-ФЗ',
        EisProcedureType::QuotationRequest,
    ],
    'Открытый конкурс в электронной форме' => ['Открытый конкурс в электронной форме', EisProcedureType::OpenCompetition],
    'Открытый конкурс п.8ч.1ст.33' => [
        'Открытый конкурс в электронной форме на проведение работ по строительству, реконструкции, '
        .'кап. ремонту, сносу объекта кап. строительства в соответствии с п. 8 ч. 1 ст. 33 Закона '
        .'№ 44-ФЗ',
        EisProcedureType::OpenCompetition,
    ],
    'ч.12 ст.93' => [
        'Закупка, осуществляемая в соответствии с частью 12 статьи 93 Закона № 44-ФЗ',
        EisProcedureType::Part12Article93Purchase,
    ],
    'Капремонт МКД' => [
        'Электронный аукцион на оказание услуг или выполнение работ по капитальному ремонту общего '
        .'имущества в многоквартирном доме',
        EisProcedureType::CapitalRepairAuction,
    ],
    'Предварительный отбор' => [
        'Предварительный отбор квалифицированных подрядных организаций',
        EisProcedureType::PreliminarySelection,
    ],
]);

test('неизвестная метка возвращает null', function () {
    expect(EisProcedureType::fromRawLabel(raw: 'Совершенно новый способ'))->toBeNull();
});
