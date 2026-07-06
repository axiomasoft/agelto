<?php

declare(strict_types=1);

use App\Models\Procurement\Registry;
use App\Services\Procurement\Eis\EisCardUrlBuilder;
use App\Services\Procurement\Exceptions\CardUrlTemplateMissingException;

test('resolveProcedureUrlType резолвит тип процедуры в сегмент URL по settings.procedure_url_types', function () {
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => [
            'procedure_url_types' => ['electronic_auction' => 'ea20'],
        ],
    ]);

    $procedureUrlType = app(EisCardUrlBuilder::class)->resolveProcedureUrlType(
        registry: $registry,
        procedureType: 'electronic_auction',
    );

    expect($procedureUrlType)->toBe('ea20');
});

test('resolveProcedureUrlType бросает исключение при отсутствии маппинга типа процедуры', function () {
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => ['procedure_url_types' => []],
    ]);

    app(EisCardUrlBuilder::class)->resolveProcedureUrlType(registry: $registry, procedureType: 'electronic_auction');
})->throws(CardUrlTemplateMissingException::class);

test('resolveProcedureUrlType бросает исключение при null procedure_type', function () {
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => ['procedure_url_types' => ['electronic_auction' => 'ea20']],
    ]);

    app(EisCardUrlBuilder::class)->resolveProcedureUrlType(registry: $registry, procedureType: null);
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
        sourceNumber: '0173200001234000001',
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
        sourceNumber: '0173200001234000001',
    );
})->throws(CardUrlTemplateMissingException::class);
