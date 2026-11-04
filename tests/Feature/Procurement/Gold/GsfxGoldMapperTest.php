<?php

declare(strict_types=1);

use App\Dto\Procurement\Prepare\Gsfx\GsfxAddZatr;
use App\Dto\Procurement\Prepare\Gsfx\GsfxAddZatrGlava;
use App\Dto\Procurement\Prepare\Gsfx\GsfxChapter;
use App\Dto\Procurement\Prepare\Gsfx\GsfxCoefficient;
use App\Dto\Procurement\Prepare\Gsfx\GsfxDocument;
use App\Dto\Procurement\Prepare\Gsfx\GsfxIndex;
use App\Dto\Procurement\Prepare\Gsfx\GsfxIndexCatalog;
use App\Dto\Procurement\Prepare\Gsfx\GsfxPassport;
use App\Dto\Procurement\Prepare\Gsfx\GsfxPosition;
use App\Dto\Procurement\Prepare\Gsfx\GsfxResource;
use App\Dto\Procurement\Prepare\Gsfx\GsfxTzm2022;
use App\Dto\Procurement\Prepare\Gsfx\GsfxWorkType;
use App\Dto\Procurement\Prepare\Gsfx\GsfxWorkTypeGroup;
use App\Services\Procurement\Gold\GoldValidator;
use App\Services\Procurement\Gold\GsfxGoldMapper;
use App\Services\Procurement\Prepare\Gsfx\GsfxReader;

/**
 * `array<string, GsfxIndex>` с ключом РОВНО ОДИН code — через runtime-переменную, не литерал
 * (`['5' => …]` PHP приводит числовую строку-ключ к `int`, и phpstan тогда видит `array<int,
 * GsfxIndex>` против объявленного `array<string, GsfxIndex>`; `GsfxReader::parseIndexSubCatalog()`
 * строит ключи тем же приёмом — через переменную).
 *
 * @return array<string, GsfxIndex>
 */
function gsfxIndexCatalogEntry(string $code, string $caption, ?string $mt, ?string $em): array
{
    return [$code => new GsfxIndex(code: $code, caption: $caption, mt: $mt, em: $em)];
}

/**
 * `GsfxDocument` → gold §8.2. Синтетический документ ниже собирает один раздел с двумя
 * позициями (ресурсный метод + прямая цена каталога) — тот же профиль, что реальный эталон
 * `EST-GSFX-LSR-001`, но без 155 МБ корпуса.
 */
function buildSyntheticGsfxDocument(): GsfxDocument
{
    return new GsfxDocument(
        passport: new GsfxPassport(
            doc_type: 'sdtSmeta',
            loc_num: 'ЛСР-ТЕСТ-01',
            object_name: 'Тестовый объект — ремонт помещения',
            total: '12345,67',
            calc_method: 'Res',
            normative_base: 'ФСНБ-2022 (с Изм. 1-13)',
            program_version: '15.3.5.55343',
            modified_at: '05.02.2026 19:17:02',
        ),
        chapters: [
            new GsfxChapter(
                caption: '2 этаж. Полы',
                sys_id: '2',
                positions: [
                    new GsfxPosition(
                        name: 'Устройство стяжек: цементных толщиной 20 мм',
                        number: '1',
                        code: 'ГЭСН11-01-011-01',
                        unit: '100 м2',
                        quantity: '2,035',
                        index_code: null,
                        work_type_id: '10018',
                        price_base: null,
                        price_curr: null,
                        indices: ['2'],
                        coefficients: [
                            new GsfxCoefficient(
                                caption: 'Зимнее удорожание',
                                code: 'ЗУ-1',
                                options: 'InPos',
                                value_oz: '1,15',
                                value_em: '1,10',
                                value_zm: '1,05',
                                value_mt: '1,02',
                                level: '1',
                            ),
                        ],
                        resources: [
                            new GsfxResource(
                                resource_type: 'Tzr',
                                name: 'Средний разряд работы 2,2',
                                code: '1-100-22',
                                unit: 'чел.-ч',
                                quantity: '35,6',
                                price_base: null,
                                price_curr: '770,67',
                                index_code: null,
                            ),
                            new GsfxResource(
                                resource_type: 'Tzm',
                                name: 'Затраты труда машинистов',
                                code: '2',
                                unit: 'чел.-ч',
                                quantity: '1,27',
                                price_base: null,
                                price_curr: null,
                                index_code: null,
                            ),
                            new GsfxResource(
                                resource_type: 'Mch',
                                name: 'Подъемники одномачтовые, грузоподъемность до 500 кг',
                                code: '91.06.06-048',
                                unit: 'маш.-ч',
                                quantity: '1,27',
                                price_base: '37,32',
                                price_curr: '66,43',
                                index_code: '2',
                                tzm2022: new GsfxTzm2022(work_class: '3', quantity: '1', price: '826,22'),
                            ),
                            new GsfxResource(
                                resource_type: 'Mat',
                                name: 'Вода',
                                code: '01.7.03.01-0001',
                                unit: 'м3',
                                quantity: '3,5',
                                price_base: '35,71',
                                price_curr: '62,49',
                                index_code: '2',
                            ),
                            new GsfxResource(
                                resource_type: 'Work',
                                name: 'Подготовка основания.',
                            ),
                        ],
                    ),
                    new GsfxPosition(
                        name: 'Раствор готовый кладочный, цементно-песчаный, М100',
                        number: '2',
                        code: 'ФСБЦ-04.3.01.09-0054',
                        unit: 'м3',
                        quantity: '4,1514',
                        index_code: '5',
                        work_type_id: null,
                        price_base: '3404,62',
                        price_curr: '6741,15',
                    ),
                ],
            ),
        ],
        work_type_groups: [
            new GsfxWorkTypeGroup(
                caption: 'Общестроительные работы',
                id: '0',
                catalog_type: 'Виды работ 2001г',
                work_types: [
                    new GsfxWorkType(
                        caption: 'Полы',
                        id: '10018',
                        category: 'Construction',
                        nr_code: 'Пр/812-011.0-1',
                        sp_code: 'Пр/774-011.0',
                        nacl: '112',
                        nacl_curr: '112',
                        plan: '65',
                        plan_curr: '65',
                    ),
                ],
            ),
        ],
        add_zatr_glavas: [
            new GsfxAddZatrGlava(glava: '8', items: [
                new GsfxAddZatr(caption: 'Временные здания и сооружения', value: '0,2', options: 'Percent InSmr HideZero'),
            ]),
            new GsfxAddZatrGlava(glava: '10', items: []),
            new GsfxAddZatrGlava(glava: '15', items: [
                new GsfxAddZatr(caption: 'Компенсация НДС при УСН', formula: '(МАТ+ЭМ-ЗПМ)*0,22', options: 'Percent HideZero'),
            ]),
        ],
        index_catalog: new GsfxIndexCatalog(
            positions: gsfxIndexCatalogEntry('5', 'Изделия стальные', '1,50', null),
            resources: gsfxIndexCatalogEntry('2', 'Электроэнергия', '1,02', '3,10'),
        ),
        coefficients: [
            new GsfxCoefficient(caption: 'К СМР в целом', code: 'К-1', options: 'InPos', value_oz: '1,08'),
        ],
        unmapped: ['SimilarResGroups' => 1],
    );
}

test('позиция с прямой ценой каталога несёт обе цены и не теряет точность', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    $row = $gold['estimates'][0]['rows'][2];
    expect($row['name'])->toBe('Раствор готовый кладочный, цементно-песчаный, М100')
        ->and($row['total_price_base'])->toBe('3404.62')
        ->and($row['total_price_curr'])->toBe('6741.15')
        ->and($row['index_code'])->toBe('5')
        ->and($row['index_value'])->toBe('1.50');
});

test('ресурсы привязаны к своей позиции и получают тип по словарю §2.2', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    $resources = $gold['estimates'][0]['rows'][1]['resources'];
    // Не array_column по name: Tzm2022 добавляет синтетическую запись с ИМЕНЕМ родителя (Mch) —
    // ключуя по name, потерял бы запись Mch под дублирующимся ключом.
    $types = array_map(static fn (array $r): string => $r['resource_type'], $resources);

    expect($types)->toBe(['labor', 'labor', 'machine', 'labor', 'material', 'other']);
});

test('row_index сквозной и включает заголовок раздела', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    $rows = $gold['estimates'][0]['rows'];
    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray(['row_index' => 1, 'row_type' => 'section_header', 'name' => '2 этаж. Полы'])
        ->and($rows[1]['row_index'])->toBe(2)
        ->and($rows[1]['row_type'])->toBe('item')
        ->and($rows[2]['row_index'])->toBe(3);
});

test('gsfx-контейнер всегда даёт ровно один элемент estimates[]', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    expect($gold['estimates'])->toHaveCount(1);
});

test('подпись индекса не хранится — в gold попадает только значение', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    $encoded = json_encode($gold, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('Изделия стальные')
        ->not->toContain('Электроэнергия');
});

test('результат маппера проходит GoldValidator без ошибок', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    expect((new GoldValidator)->validate($gold))->toBe([]);
});

test('битая позиция без Caption даёт понятную ошибку валидации, а не пустой валидный gold', function () {
    $document = buildSyntheticGsfxDocument();
    $broken = new GsfxDocument(
        passport: $document->passport,
        chapters: [
            new GsfxChapter(caption: 'Раздел', positions: [
                new GsfxPosition(name: ''), // Caption отсутствовал в Data.xml
            ]),
        ],
    );

    $gold = (new GsfxGoldMapper)->toGold($broken);
    $errors = (new GoldValidator)->validate($gold);

    // rows[0] — заголовок раздела (row_index=1, name='Раздел'), rows[1] — сама битая позиция.
    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->toContain('rows.1.name');
});

test('Tzm и Tzm2022 не суммируются в сводных трудозатратах машинистов', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    // Tzm@Quantity=1,27 — единственный вклад; Tzm2022@Quantity≡1 (ставка) в сумму не входит.
    expect($gold['estimates'][0]['labor_hours_machinists'])->toBe('1.270000')
        ->and($gold['estimates'][0]['labor_hours_workers'])->toBe('35.600000');
});

test('Tzm2022 попадает в resources[] отдельной строкой resource_type=labor без unit', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    $resources = $gold['estimates'][0]['rows'][1]['resources'];
    $tzm2022 = array_values(array_filter(
        $resources,
        static fn (array $r): bool => $r['quantity'] === '1' && $r['price_curr'] === '826.22',
    ));

    expect($tzm2022)->toHaveCount(1)
        ->and($tzm2022[0]['resource_type'])->toBe('labor')
        ->and($tzm2022[0]['unit'])->toBeNull()
        ->and($tzm2022[0]['name'])->toBe('Подъемники одномачтовые, грузоподъемность до 500 кг');
});

test('ресурсный метод: цена позиции без прямой цены — сумма quantity×price_curr ресурсов, base пуст', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    $row = $gold['estimates'][0]['rows'][1];
    // 35,6×770,67 (Tzr) + 1,27×66,43 (Mch) + 3,5×62,49 (Mat) — Tzm без цены, Work без
    // количества/цены, синтетическая Tzm2022-строка в сумму НЕ входит (её "826,22" — ставка
    // машиниста по методике 2022, а не вклад в стоимость позиции; носителя для такого сложения
    // ни один Input не даёт).
    expect($row['total_price_base'])->toBeNull()
        ->and($row['total_price_curr'])->toBe(
            bcadd(
                bcadd(bcmul('35.6', '770.67', 10), bcmul('1.27', '66.43', 10), 10),
                bcmul('3.5', '62.49', 10),
                2,
            ),
        );
});

test('коэффициент позиции раскладывается по девяти полям RowModifier без kind', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    $modifiers = $gold['estimates'][0]['rows'][1]['attributes']['modifiers'];
    expect($modifiers)->toHaveCount(1)
        ->and($modifiers[0])->toMatchArray([
            'name' => 'Зимнее удорожание',
            'kind' => null,
            'code' => 'ЗУ-1',
            'value' => null,
            'value_labor' => '1.15',
            'value_machines' => '1.10',
            'value_machinists_wage' => '1.05',
            'value_materials' => '1.02',
            'level' => 1,
        ]);
});

test('документный коэффициент не попадает ни в одну строку, но виден в unmappedReport', function () {
    $document = buildSyntheticGsfxDocument();
    $gold = (new GsfxGoldMapper)->toGold($document);

    $modifierCodes = [];
    foreach ($gold['estimates'][0]['rows'] as $row) {
        foreach ($row['attributes']['modifiers'] ?? [] as $modifier) {
            $modifierCodes[] = $modifier['code'];
        }
    }
    expect($modifierCodes)->not->toContain('К-1');

    $report = (new GsfxGoldMapper)->unmappedReport($document);
    expect($report['Document.coefficients'])->toBe(['count' => 1, 'has_carrier' => false]);
});

test('work_type_id позиции резолвится в дерево видов работ, у безссылочной позиции — null', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    expect($gold['estimates'][0]['rows'][1]['work_type_name'])->toBe('Полы')
        ->and($gold['estimates'][0]['rows'][2]['work_type_name'])->toBeNull()
        ->and($gold['estimates'][0]['work_types'][0])->toMatchArray([
            'title' => 'Общестроительные работы',
            'overhead_code' => null,
        ])
        ->and($gold['estimates'][0]['work_types'][0]['children'][0])->toMatchArray([
            'title' => 'Полы',
            'overhead_code' => 'Пр/812-011.0-1',
            'overhead_rate' => '112',
            'profit_rate' => '65',
        ]);
});

test('начисления по главам: процентная ставка идёт в rate, формула без Value не хранится, итог сметы — последней строкой', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    $footer = $gold['estimates'][0]['totals']['footer'];
    expect($footer)->toHaveCount(3) // глава 10 без <AddZatr> пропущена целиком
        ->and($footer[0])->toMatchArray([
            'label' => 'Временные здания и сооружения',
            'value' => null,
            'chapter_number' => 8,
            'rate' => '0.2',
        ])
        ->and($footer[1])->toMatchArray([
            // формула есть, Value нет — строка остаётся (label виден), но value/rate оба null:
            // Формула не хранится и не выдумывается из значения.
            'label' => 'Компенсация НДС при УСН',
            'value' => null,
            'chapter_number' => 15,
            'rate' => null,
        ])
        ->and($footer[2])->toMatchArray([
            'label' => 'Итого по смете',
            'value' => '12345.67',
            'kind' => 'total',
        ]);
});

test('калькуляционный метод, базовый год и цитата уровня цен резолвятся из паспорта', function () {
    $gold = (new GsfxGoldMapper)->toGold(buildSyntheticGsfxDocument());

    expect($gold['estimates'][0]['calc_method'])->toBe('resource')
        ->and($gold['estimates'][0]['base_year'])->toBe(2022)
        ->and($gold['estimates'][0]['price_level'])->toBe('ФСНБ-2022 (с Изм. 1-13)')
        ->and($gold['estimates'][0]['passport']['address'])->toBe('Тестовый объект — ремонт помещения')
        ->and($gold['estimates'][0]['passport']['target'])->toBeNull()
        ->and($gold['estimates'][0]['passport']['generator'])->toBe('ГрандСмета 15.3.5.55343')
        ->and($gold['estimates'][0]['estimate_number'])->toBe('ЛСР-ТЕСТ-01')
        ->and($gold['estimates'][0]['rows_expected'])->toBe(2);
});

test('на реальном эталоне EST-GSFX-LSR-001 маппер проходит GoldValidator без ошибок', function () {
    if (! is_dir(base_path('estimates/files')) || ! file_exists(base_path('estimates/files/Администрация культуры и спорта 3.gsfx'))) {
        $this->markTestSkipped('Реальный эталон корпуса недоступен на этом дереве (D1 — файлы не коммитятся в git).');
    }

    $zip = new ZipArchive;
    $zip->open(base_path('estimates/files/Администрация культуры и спорта 3.gsfx'));
    $properties = $zip->getFromName('Properties.txt');
    $dataXml = $zip->getFromName('Data.xml');
    $zip->close();

    $document = app(GsfxReader::class)->parse($properties, $dataXml);
    $gold = (new GsfxGoldMapper)->toGold($document);

    $errors = (new GoldValidator)->validate($gold);

    expect($errors)->toBe([])
        ->and($gold['estimates'])->toHaveCount(1)
        ->and($gold['estimates'][0]['rows'])->not->toBeEmpty();

    $positionCount = 0;
    foreach ($gold['estimates'][0]['rows'] as $row) {
        if ($row['row_type'] === 'item') {
            $positionCount++;
        }
    }

    // 110 <Position> в Data.xml (`grep -c '<Position' Data.xml`, snapshot корпуса edac..52708b).
    expect($positionCount)->toBe(110);
});
