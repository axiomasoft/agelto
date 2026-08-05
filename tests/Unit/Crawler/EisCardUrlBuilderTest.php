<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Eis\EisCardUrlBuilder;
use App\Services\Procurement\Exceptions\CardUrlTemplateMissingException;

test('resolveProcedureUrlType резолвит СЫРУЮ метку способа закупки в сегмент URL по settings.procedure_url_types (П-8)', function () {
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => [
            'procedure_url_types' => ['Электронный аукцион' => 'ea20'],
        ],
    ]);

    $procedureUrlType = app(EisCardUrlBuilder::class)->resolveProcedureUrlType(
        registry: $registry,
        procedureTypeRaw: 'Электронный аукцион',
    );

    expect($procedureUrlType)->toBe('ea20');
});

test('resolveProcedureUrlType бросает исключение при отсутствии маппинга типа процедуры', function () {
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => ['procedure_url_types' => []],
    ]);

    app(EisCardUrlBuilder::class)->resolveProcedureUrlType(registry: $registry, procedureTypeRaw: 'Электронный аукцион');
})->throws(CardUrlTemplateMissingException::class);

test('resolveProcedureUrlType бросает исключение при null procedure_type_raw', function () {
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => ['procedure_url_types' => ['Электронный аукцион' => 'ea20']],
    ]);

    app(EisCardUrlBuilder::class)->resolveProcedureUrlType(registry: $registry, procedureTypeRaw: null);
})->throws(CardUrlTemplateMissingException::class);

test('buildUrl строит полный URL вкладки из card_url_templates с подстановкой regNumber', function () {
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => [
            'card_url_templates' => [
                'ea20' => [
                    'common-info' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber={regNumber}',
                ],
            ],
        ],
    ]);

    $url = app(EisCardUrlBuilder::class)->buildUrl(
        registry: $registry,
        procedureUrlType: 'ea20',
        tab: 'common-info',
        registryNumber: '0173200001234000001',
    );

    expect($url)->toBe(
        'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=0173200001234000001',
    );
});

test('buildUrl бросает исключение при отсутствии шаблона для типа+вкладки', function () {
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => ['card_url_templates' => ['ea20' => []]],
    ]);

    app(EisCardUrlBuilder::class)->buildUrl(
        registry: $registry,
        procedureUrlType: 'ea20',
        tab: 'common-info',
        registryNumber: '0173200001234000001',
    );
})->throws(CardUrlTemplateMissingException::class);

test('buildUrl возвращает null для явного маркера «вкладки нет по природе» (D121), а не бросает исключение', function () {
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => [
            'card_url_templates' => [
                'ea615' => ['supplier-results' => null],
            ],
        ],
    ]);

    $url = app(EisCardUrlBuilder::class)->buildUrl(
        registry: $registry,
        procedureUrlType: 'ea615',
        tab: 'supplier-results',
        registryNumber: '0173200001234000001',
    );

    expect($url)->toBeNull();
});

test('buildUrl различает маркер null и отсутствующий ключ — отсутствие ключа остаётся ошибкой конфигурации (D121)', function () {
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => [
            'card_url_templates' => [
                'ea615' => ['common-info' => 'https://zakupki.gov.ru/epz/order/notice/ea615/view/common-info.html?regNumber={regNumber}'],
            ],
        ],
    ]);

    app(EisCardUrlBuilder::class)->buildUrl(
        registry: $registry,
        procedureUrlType: 'ea615',
        tab: 'supplier-results',
        registryNumber: '0173200001234000001',
    );
})->throws(CardUrlTemplateMissingException::class);
