<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Exceptions\Platforms\Eis\CardUrlTemplateMissingException;
use App\Services\Platforms\Eis\Card\EisCardCatalog;
use App\Services\Platforms\Eis\Card\EisCardUrlBuilder;

test('resolveProcedureUrlType резолвит СЫРУЮ метку способа закупки в сегмент URL по settings.procedure_url_types (П-8)', function () {
    $builder = new EisCardUrlBuilder((new EisCardCatalog)->routes());
    expect($builder->resolveProcedureUrlType('fz44', 'Электронный аукцион'))->toBe('ea20');
});

test('resolveProcedureUrlType бросает исключение при отсутствии маппинга типа процедуры', function () {
    $builder = new EisCardUrlBuilder(new EisCardRoutes((new EisCardCatalog)->cardUrlTemplates()));
    $builder->resolveProcedureUrlType('fz44', 'Электронный аукцион');
})->throws(CardUrlTemplateMissingException::class);

test('resolveProcedureUrlType бросает исключение при null procedure_type_raw', function () {
    (new EisCardUrlBuilder((new EisCardCatalog)->routes()))->resolveProcedureUrlType('fz44', null);
})->throws(CardUrlTemplateMissingException::class);

test('buildUrl строит полный URL вкладки из card_url_templates с подстановкой regNumber', function () {
    $url = (new EisCardUrlBuilder((new EisCardCatalog)->routes()))->buildUrl('fz44', 'ea20', 'common-info', '0173200001234000001');

    expect($url)->toBe('https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=0173200001234000001');
});

test('buildUrl бросает исключение при отсутствии шаблона для типа+вкладки', function () {
    (new EisCardUrlBuilder(new EisCardRoutes([])))->buildUrl('fz44', 'ea20', 'common-info', '0173200001234000001');
})->throws(CardUrlTemplateMissingException::class);

test('buildUrl возвращает null для явного маркера «вкладки нет по природе» (D121), а не бросает исключение', function () {
    $url = (new EisCardUrlBuilder((new EisCardCatalog)->routes()))->buildUrl('fz44', 'ea615', 'supplier-results', '0173200001234000001');
    expect($url)->toBeNull();
});

test('buildUrl различает маркер null и отсутствующий ключ — отсутствие ключа остаётся ошибкой конфигурации (D121)', function () {
    $templates = (new EisCardCatalog)->cardUrlTemplates();
    unset($templates['ea615']['supplier-results']);

    // An incomplete four-part contract is rejected at construction, before a physical read.
    new EisCardRoutes($templates);
})->throws(InvalidArgumentException::class, 'Incomplete EIS card routes');
